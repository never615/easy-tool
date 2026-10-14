<?php

namespace Mallto\Tool\Domain\SwooleTaskMonitor;

use Hhxsv5\LaravelS\Swoole\Task\Task;
use Throwable;

/**
 * 为 Swoole Task 提供标准化运输层生命周期监控的抽象基类。
 *
 * 子类只实现 handleTask()；本类负责将一次 worker 执行收敛为 started 加一个互斥终态：
 * 正常返回为 finished，调用 markRejected() 后正常返回为 rejected，抛出 Throwable 为
 * failed。finished 只代表 handleTask() 返回，不能表示其触发的业务链路已经最终完成。
 *
 * SwooleTaskMonitor::deliver() 在投递前为采样命中的实例写入追踪上下文。本类同样可被
 * 直接执行，此时只记录 summary，且按需生成的 task ID 不会形成可关联的提交记录。
 */
abstract class MonitoredSwooleTask extends Task
{
    // SerializesModels reflects the concrete child. Inherited private properties would be silently lost.
    protected ?string $swooleTaskMonitorId = null;
    protected array $swooleTaskMonitorContext = [];
    protected bool $swooleTaskMonitorTraceSampled = false;
    protected ?array $swooleTaskMonitorRejection = null;
    /** Only scalars cross IPC; no collector, closure or Redis connection is serialized. */
    protected ?array $swooleTaskExecutionContext = null;

    /**
     * Swoole 调用的固定执行入口，维护监控生命周期而不允许子类绕过它。
     *
     * 先记录 started 并开始计时，再调用子类业务实现。异常优先于已标记的拒绝状态：异常
     * 会记录 failed 后原样抛出，供 Swoole 保持既有错误处理；无异常时才根据 rejection
     * 标记记录 rejected 或 finished。因此每次调用最多写入一个 worker 终态。
     */
    final public function handle()
    {
        $entry = $this->swooleTaskExecutionContext !== null ? hrtime(true) : null;
        $diagnostics = $this->swooleTaskExecutionContext !== null
            ? SwooleTaskExecutionDiagnostics::start($this->swooleTaskExecutionContext['run_id']) : null;
        $durations = [];
        $outcome = 'finished';
        $monitorFailures = $diagnostics !== null ? SwooleTaskMonitor::recordFailureCount() : 0;
        $start = microtime(true);
        $startedAt = $diagnostics?->begin();
        SwooleTaskMonitor::recordStarted($this);
        $businessAt = $diagnostics?->begin();
        if ($startedAt !== null && $businessAt !== null) {
            $durations['monitor_started'] = $businessAt - $startedAt;
        }

        try {
            $this->handleTask();
        } catch (Throwable $exception) {
            $outcome = 'failed';
            throw $exception;
        } finally {
            $terminalAt = $diagnostics?->begin();
            if ($businessAt !== null && $terminalAt !== null) {
                $durations['handle_task'] = $terminalAt - $businessAt;
            }
            // Preserve the original duration boundary: started monitor plus business, excluding terminal monitor.
            if ($outcome === 'failed') {
                SwooleTaskMonitor::recordFailed($this, $this->elapsedMs($start), $exception);
            } elseif ($this->swooleTaskMonitorRejection !== null) {
                $outcome = 'rejected';
                SwooleTaskMonitor::recordRejected($this, $this->elapsedMs($start),
                    $this->swooleTaskMonitorRejection['reason'], $this->swooleTaskMonitorRejection['context']);
            } else {
                SwooleTaskMonitor::recordFinished($this, $this->elapsedMs($start));
            }
            if ($diagnostics !== null) {
                try {
                    $endedAt = $diagnostics->begin();
                    if ($terminalAt !== null && $endedAt !== null) {
                        $durations['monitor_terminal'] = $endedAt - $terminalAt;
                    }
                    if ($entry !== null && $endedAt !== null) {
                        $durations['worker_total'] = $endedAt - $entry;
                        $durations['submit_to_worker'] = $entry - $this->swooleTaskExecutionContext['submitted_at_ns'];
                    }
                    $diagnostics->record($this->swooleTaskExecutionContext['channel'], $outcome, $durations,
                        SwooleTaskMonitor::recordFailureCount() - $monitorFailures);
                    $diagnostics->flush();
                } catch (Throwable) {
                    // Observation must never replace a business result or its original Throwable.
                }
            }
        }
    }

    /** Called immediately before the first transport, after producer submitted monitoring. */
    final public function prepareSwooleTaskExecutionDiagnostics(): void
    {
        if ($this->swooleTaskExecutionContext !== null || !isset(SwooleTaskExecutionDiagnostics::CHANNELS[static::class])) {
            return;
        }
        try {
            if (config('swoole_task_monitor.execution_diagnostics.enabled', false) !== true) {
                return;
            }
            $run = config('swoole_task_monitor.execution_diagnostics.run_id');
            if (!SwooleTaskExecutionDiagnostics::validRun($run)) {
                return;
            }
            $this->swooleTaskExecutionContext = ['run_id' => $run,
                'channel' => SwooleTaskExecutionDiagnostics::CHANNELS[static::class], 'submitted_at_ns' => hrtime(true)];
        } catch (Throwable) {
            // Missing worker coverage is rejected by the evidence validator, never repaired as zero time.
        }
    }

    /**
     * 子类实现实际业务逻辑的扩展点。
     *
     * 无法处理但不属于异常的输入应调用 markRejected() 后返回；需要让 Swoole 感知的
     * 故障应继续抛出异常，而不是转换为 rejected。
     */
    abstract protected function handleTask(): void;

    /**
     * 将当前 Task 标记为业务拒绝，但不立即中断 handleTask()。
     *
     * 最后一次调用覆盖先前原因；只有 handleTask() 随后正常返回时，handle() 才会写入
     * rejected。若之后抛出异常，failed 为唯一终态，标记不会被持久化。
     *
     * @param array<string, mixed> $context 用于诊断拒绝分支的上下文
     */
    final protected function markRejected(string $reason, array $context = []): void
    {
        $this->swooleTaskMonitorRejection = [
            'reason' => $reason,
            'context' => $context,
        ];
    }

    /**
     * 接收投递端写入的追踪关联信息。
     *
     * 由 SwooleTaskMonitor::deliver() 在 Task 序列化前调用。即使当前未采样也会保存调用方
     * 上下文，但只有 traceSampled 为 true 时 Store 才会把它关联到 submitted/start/running
     * 等 trace 状态。
     *
     * @param array<string, mixed> $context 投递点提供的诊断上下文
     */
    public function setSwooleTaskMonitorContext(string $taskId, array $context = [], bool $traceSampled = false): void
    {
        $this->swooleTaskMonitorId = $taskId;
        $this->swooleTaskMonitorContext = $context;
        $this->swooleTaskMonitorTraceSampled = $traceSampled;
    }

    /**
     * 返回当前 Task 的追踪 ID，必要时惰性生成一个。
     *
     * 正常异步投递时 ID 来自 deliver()；惰性生成保证直接调用 handle() 的实例也能满足
     * trace 存储接口，但该 ID 没有对应 submitted 事件。
     */
    public function swooleTaskMonitorId(): string
    {
        if (!$this->swooleTaskMonitorId) {
            $this->swooleTaskMonitorId = SwooleTaskMonitor::newTaskId();
        }

        return $this->swooleTaskMonitorId;
    }

    /**
     * 返回投递端传入的原始监控上下文，不作业务字段解释或修改。
     *
     * @return array<string, mixed>
     */
    public function swooleTaskMonitorContext(): array
    {
        return $this->swooleTaskMonitorContext;
    }

    /**
     * 指示本次投递是否命中 trace 采样。
     *
     * false 时 SwooleTaskMonitor 仅写聚合指标，不创建 submitted/running 临时 Redis 状态。
     */
    public function swooleTaskMonitorTraceSampled(): bool
    {
        return $this->swooleTaskMonitorTraceSampled;
    }

    /**
     * 返回供监控聚合使用的负载大小，保证结果不为负数。
     *
     * 子类通过 monitorPayloadBytes() 声明应计入的业务负载；该数值仅用于容量与耗时分析，
     * 不触发序列化，也不应被当成真实 Swoole 序列化包大小。
     */
    public function swooleTaskMonitorPayloadBytes(): int
    {
        return max(0, $this->monitorPayloadBytes());
    }

    /**
     * 子类可覆盖以计算本 Task 的业务负载字节数。
     *
     * 默认零表示该类型不提供大小估算。实现应保持轻量、无副作用，因该方法会在投递前和
     * worker 终态记录时调用。
     */
    protected function monitorPayloadBytes(): int
    {
        return 0;
    }

    /**
     * 计算常见负载值的近似 UTF-8 JSON 字节数，供子类实现 monitorPayloadBytes() 复用。
     *
     * 字符串直接按字节计数；其他值以无转义 Unicode 和斜杠的 JSON 表示计数。无法 JSON
     * 编码的值返回零，宁可缺失监控大小也不影响 Task 执行。
     */
    protected function monitorValueBytes(mixed $value): int
    {
        if (is_string($value)) {
            return strlen($value);
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return 0;
        }

        return strlen($encoded);
    }

    /**
     * 将本次 handle() 的单调墙钟差转换为毫秒整数。
     *
     * 此耗时从 recordStarted() 前开始，覆盖监控记录与 handleTask() 的执行，但不包含
     * 投递队列等待时间；后者仅在 trace 模式由 Store 的 submitted 时间计算。
     */
    private function elapsedMs(float $start): int
    {
        return (int)round((microtime(true) - $start) * 1000);
    }
}
