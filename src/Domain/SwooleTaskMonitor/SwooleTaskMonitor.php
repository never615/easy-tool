<?php

namespace Mallto\Tool\Domain\SwooleTaskMonitor;

use Hhxsv5\LaravelS\Swoole\Task\Task;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SwooleTaskMonitor：Swoole Task 生命周期监控组件。
 *
 * 本类负责 Task 运输层的可观测性，而非业务结果：submitted、delivered、started 和
 * finished 仅描述投递及 handle() 的执行状态；finished 表示任务方法正常返回，不能据此
 * 推断定位、消息发布等后续业务已成功完成。
 *
 * 支持两种记录粒度：summary 仅累加每类任务的聚合计数和耗时；trace 按采样率额外保存
 * task ID、负载大小、上下文、排队时长及运行态。所有写入均经 safeRecord() 隔离，Redis
 * 或监控代码故障不得改变业务 Task 的投递和异常传播语义。
 */
class SwooleTaskMonitor
{
    private const MODE_OFF = 'off';
    private const MODE_SUMMARY = 'summary';
    private const MODE_TRACE = 'trace';

    private static int $lastRecordFailureLogAt = 0;

    /**
     * 投递 Task，并在 Swoole 接收任务前后记录运输层事件。
     *
     * 采样命中时，先生成或复用 task ID，并将其与调用方上下文写回支持监控协议的 Task；
     * 随后记录 submitted。Task::deliver() 抛出异常时记录 deliver_failed 后原样抛出；
     * 返回 false 时记录 deliver_failed 并返回 false；其余成功返回值才会记录 delivered。
     * 未使用 MonitoredSwooleTask 的普通 Task 仍可得到 summary 指标。
     *
     * @param array<string, mixed> $context 用于追踪和诊断的调用方上下文
     */
    public static function deliver(Task $task, array $context = []): bool
    {
        $taskClass = get_class($task);
        $traceSampled = self::traceSampled();
        $taskId = $traceSampled ? self::taskId($task) : '';
        $payloadBytes = $traceSampled ? self::payloadBytes($task) : 0;

        if (method_exists($task, 'setSwooleTaskMonitorContext')) {
            $task->setSwooleTaskMonitorContext($taskId, $context, $traceSampled);
        }

        self::safeRecord(function (SwooleTaskMonitorStore $store) use ($traceSampled, $taskId, $taskClass, $payloadBytes, $context) {
            if ($traceSampled) {
                $store->recordSubmitted($taskId, $taskClass, $payloadBytes, $context);

                return;
            }

            $store->recordSubmittedSummary($taskClass);
        });

        try {
            $delivered = Task::deliver($task);
        } catch (Throwable $exception) {
            self::safeRecord(function (SwooleTaskMonitorStore $store) use ($taskId, $taskClass, $traceSampled, $exception) {
                $store->recordDeliverFailed(
                    $taskId,
                    $taskClass,
                    'exception: ' . $exception->getMessage(),
                    $exception,
                    $traceSampled
                );
            });

            throw $exception;
        }

        if ($delivered === false) {
            self::safeRecord(function (SwooleTaskMonitorStore $store) use ($taskId, $taskClass, $traceSampled) {
                $store->recordDeliverFailed($taskId, $taskClass, 'Task::deliver returned false', null, $traceSampled);
            });

            return false;
        }

        self::safeRecord(function (SwooleTaskMonitorStore $store) use ($taskClass) {
            $store->recordDelivered($taskClass);
        });

        return true;
    }

    /**
     * 记录任务在调用侧被丢弃，尚未进入 Task worker 的情形。
     *
     * 典型原因包括队列已满、投递接口返回 false 或入口解析失败。它不是已执行 Task 的
     * 生命周期终态，调用方应在确认不再投递或处理该任务时显式调用。
     *
     * @param string|Task $task 任务实例或任务类名
     * @param array<string, mixed> $context 丢弃发生位置的诊断上下文
     */
    public static function recordDropped(string|Task $task, string $reason, array $context = []): void
    {
        $taskClass = is_string($task) ? $task : get_class($task);

        self::safeRecord(function (SwooleTaskMonitorStore $store) use ($taskClass, $reason, $context) {
            $store->recordDropped($taskClass, $reason, $context);
        });
    }

    /**
     * 记录调用侧因限流而未投递的 Task。
     *
     * 此指标独立于 dropped，用于区分容量或投递故障和预期的流量保护决策。
     *
     * @param string|Task $task 任务实例或任务类名
     * @param array<string, mixed> $context 限流键、等待时间等诊断上下文
     */
    public static function recordRateLimited(string|Task $task, string $reason, array $context = []): void
    {
        $taskClass = is_string($task) ? $task : get_class($task);

        self::safeRecord(function (SwooleTaskMonitorStore $store) use ($taskClass, $reason, $context) {
            $store->recordRateLimited($taskClass, $reason, $context);
        });
    }

    /**
     * 记录未进入 Swoole Task、而在当前进程直接处理完成的分支。
     *
     * 该事件用于解释为什么某类工作没有 submitted/started 记录，例如小负载同步降级；
     * 不应与异步 Task 的 finished 指标合并理解。
     *
     * @param string|Task $task 任务实例或任务类名
     * @param array<string, mixed> $context 直接处理原因及诊断上下文
     */
    public static function recordDirectHandled(string|Task $task, string $reason, array $context = []): void
    {
        $taskClass = is_string($task) ? $task : get_class($task);

        self::safeRecord(function (SwooleTaskMonitorStore $store) use ($taskClass, $reason, $context) {
            $store->recordDirectHandled($taskClass, $reason, $context);
        });
    }

    /**
     * 记录当前进程直接处理路径中的显式拒绝。
     *
     * 适用于无需投递 Task 即可判定输入不支持或不应继续处理的场景，与 worker 内
     * MonitoredSwooleTask::markRejected() 所记录的 rejected 终态相区分。
     *
     * @param string|Task $task 任务实例或任务类名
     * @param array<string, mixed> $context 拒绝原因相关的诊断上下文
     */
    public static function recordDirectRejected(string|Task $task, string $reason, array $context = []): void
    {
        $taskClass = is_string($task) ? $task : get_class($task);

        self::safeRecord(function (SwooleTaskMonitorStore $store) use ($taskClass, $reason, $context) {
            $store->recordDirectRejected($taskClass, $reason, $context);
        });
    }

    /**
     * 记录 MonitoredSwooleTask 已开始在 worker 中执行。
     *
     * trace 样本会关联 submitted 时保存的状态，以计算排队时长并建立运行态；未采样任务
     * 只更新 summary 计数。该方法由 MonitoredSwooleTask::handle() 调用，业务代码不应
     * 手动调用，以免破坏生命周期统计。
     */
    public static function recordStarted(MonitoredSwooleTask $task): void
    {
        self::safeRecord(function (SwooleTaskMonitorStore $store) use ($task) {
            if (!$task->swooleTaskMonitorTraceSampled()) {
                $store->recordStartedSummary(get_class($task));

                return;
            }

            $store->recordStarted(
                $task->swooleTaskMonitorId(),
                get_class($task),
                $task->swooleTaskMonitorPayloadBytes(),
                $task->swooleTaskMonitorContext()
            );
        });
    }

    /**
     * 记录 Task 的 handleTask() 正常返回。
     *
     * 这是异步 Task 的唯一正常终态。trace 样本同时清理运行态；summary 模式仅累计耗时。
     * 此状态不代表 Task 内触发的异步链路或业务副作用已经最终成功。
     */
    public static function recordFinished(MonitoredSwooleTask $task, int $durationMs): void
    {
        self::safeRecord(function (SwooleTaskMonitorStore $store) use ($task, $durationMs) {
            if (!$task->swooleTaskMonitorTraceSampled()) {
                $store->recordFinishedSummary(get_class($task), $durationMs);

                return;
            }

            $store->recordFinished(
                $task->swooleTaskMonitorId(),
                get_class($task),
                $durationMs,
                $task->swooleTaskMonitorPayloadBytes(),
                $task->swooleTaskMonitorContext()
            );
        });
    }

    /**
     * 记录 worker 已执行但由业务显式拒绝的 Task 终态。
     *
     * 基础 Task 上下文与拒绝点上下文会合并，后传上下文覆盖同名键。该方法由
     * MonitoredSwooleTask 在 handleTask() 返回后调用；若执行期间抛出异常，failed
     * 优先，拒绝状态不会被记录。
     *
     * @param array<string, mixed> $context 拒绝分支附加的诊断上下文
     */
    public static function recordRejected(
        MonitoredSwooleTask $task,
        int $durationMs,
        string $reason,
        array $context = []
    ): void {
        $context = array_merge($task->swooleTaskMonitorContext(), $context);

        self::safeRecord(function (SwooleTaskMonitorStore $store) use ($task, $durationMs, $reason, $context) {
            if (!$task->swooleTaskMonitorTraceSampled()) {
                $store->recordRejectedSummary(get_class($task), $durationMs, $reason, $context);

                return;
            }

            $store->recordRejected(
                $task->swooleTaskMonitorId(),
                get_class($task),
                $durationMs,
                $task->swooleTaskMonitorPayloadBytes(),
                $reason,
                $context
            );
        });
    }

    /**
     * 记录 Task 执行期间抛出异常的终态。
     *
     * 监控存储会保留安全的异常摘要并清理 trace 运行态；此方法本身不吞掉原始异常，
     * MonitoredSwooleTask 随后仍会将异常交回 Swoole 的正常错误处理流程。
     */
    public static function recordFailed(MonitoredSwooleTask $task, int $durationMs, Throwable $exception): void
    {
        self::safeRecord(function (SwooleTaskMonitorStore $store) use ($task, $durationMs, $exception) {
            if (!$task->swooleTaskMonitorTraceSampled()) {
                $store->recordFailedSummary(get_class($task), $durationMs, $exception);

                return;
            }

            $store->recordFailed(
                $task->swooleTaskMonitorId(),
                get_class($task),
                $durationMs,
                $task->swooleTaskMonitorPayloadBytes(),
                $exception,
                $task->swooleTaskMonitorContext()
            );
        });
    }

    /**
     * 获取可追踪 Task 的既有 ID，或为不支持监控协议的 Task 临时生成 ID。
     *
     * 只在 trace 采样命中时调用，避免 summary 模式为不会保存的追踪状态分配标识。
     */
    private static function taskId(Task $task): string
    {
        if (method_exists($task, 'swooleTaskMonitorId')) {
            return $task->swooleTaskMonitorId();
        }

        return self::newTaskId();
    }

    /**
     * 生成用于关联 submitted、started 及终态事件的本地唯一标识。
     *
     * 此 ID 只服务监控关联，不是业务实体主键或跨系统幂等键。
     */
    public static function newTaskId(): string
    {
        return str_replace('.', '', uniqid('', true)) . mt_rand(1000, 9999);
    }

    /**
     * 返回管理端可展示的规范化运行配置快照。
     *
     * mode=off 的优先级高于 enabled；禁用时对外统一暴露 off、零采样率和未启用追踪，
     * 防止管理端显示与实际写入行为矛盾的配置组合。
     *
     * @return array{enabled: bool, mode: string, trace_sample_rate: float, trace_enabled: bool}
     */
    public static function config(): array
    {
        $enabled = self::enabled();
        $mode = self::mode();
        $traceSampleRate = self::traceSampleRate($mode);

        return [
            'enabled' => $enabled,
            'mode' => $enabled ? $mode : self::MODE_OFF,
            'trace_sample_rate' => $enabled ? $traceSampleRate : 0.0,
            'trace_enabled' => $enabled && $traceSampleRate > 0,
        ];
    }

    /**
     * 获取 Task 声明的监控负载大小，并将异常或负值收敛为零。
     *
     * 只有实现 swooleTaskMonitorPayloadBytes() 协议的 Task 才提供精确大小；普通 Task
     * 不进行序列化估算，以避免监控本身增加投递路径开销。
     */
    private static function payloadBytes(Task $task): int
    {
        if (method_exists($task, 'swooleTaskMonitorPayloadBytes')) {
            return max(0, (int)$task->swooleTaskMonitorPayloadBytes());
        }

        return 0;
    }

    /**
     * 在监控启用时执行 Redis 记录回调，并隔离监控基础设施故障。
     *
     * 记录失败最多每 60 秒写一条 warning，避免 Redis 故障时大量 Task 放大日志噪音。
     * 回调异常被吞掉是刻意设计，保证监控为旁路能力而不是业务可用性的依赖。
     */
    private static function safeRecord(callable $callback): void
    {
        if (!self::enabled()) {
            return;
        }

        try {
            $callback(app(SwooleTaskMonitorStore::class));
        } catch (Throwable $exception) {
            $now = time();
            if ($now - self::$lastRecordFailureLogAt >= 60) {
                self::$lastRecordFailureLogAt = $now;
                Log::warning('[SwooleTaskMonitor] record failed: ' . $exception->getMessage());
            }
        }
    }

    /**
     * 判断监控是否允许写入。
     *
     * mode=off 为硬开关；其他模式再解析 enabled，以兼容配置中心可能写入的字符串布尔值。
     */
    private static function enabled(): bool
    {
        if (self::mode() === self::MODE_OFF) {
            return false;
        }

        $enabled = config('swoole_task_monitor.enabled', false);

        if (is_string($enabled)) {
            return !in_array(strtolower($enabled), ['0', 'false', 'off', 'no'], true);
        }

        return (bool)$enabled;
    }

    /**
     * 根据规范化采样率决定当前投递是否保存 trace 级详情。
     *
     * 关闭监控或采样率为零时不分配 trace 状态；采样率为一时始终命中；中间值基于百万
     * 分桶随机数判断，避免浮点比较造成边界歧义。
     */
    private static function traceSampled(): bool
    {
        if (!self::enabled()) {
            return false;
        }

        $rate = self::traceSampleRate(self::mode());
        if ($rate <= 0) {
            return false;
        }

        if ($rate >= 1) {
            return true;
        }

        return random_int(1, 1000000) <= (int)round($rate * 1000000);
    }

    /**
     * 读取并规范化监控模式。
     *
     * 仅接受 off、summary 与 trace；缺失或非法值回退到 summary，使生产环境在配置异常
     * 时保留低成本聚合指标，而不是意外开启全量追踪或完全失去监控。
     */
    private static function mode(): string
    {
        $mode = (string)config('swoole_task_monitor.mode', self::MODE_SUMMARY);

        $mode = strtolower(trim($mode));

        return in_array($mode, [self::MODE_OFF, self::MODE_SUMMARY, self::MODE_TRACE], true)
            ? $mode
            : self::MODE_SUMMARY;
    }

    /**
     * 读取 trace 采样率并约束到 [0, 1]。
     *
     * 未配置时，trace 模式默认全量采样，summary 模式默认不采样；显式空值同样使用该
     * 模式默认值，避免配置中心空字符串被转换为零而悄然改变 trace 模式语义。
     */
    private static function traceSampleRate(string $mode): float
    {
        $default = $mode === self::MODE_TRACE ? 1.0 : 0.0;
        $rate = config('swoole_task_monitor.trace_sample_rate', $default);
        if ($rate === null || $rate === '') {
            $rate = $default;
        }

        return max(0.0, min(1.0, (float)$rate));
    }
}
