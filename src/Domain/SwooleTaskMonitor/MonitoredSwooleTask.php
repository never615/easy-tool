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
    // SerializesModels 反射具体子类。继承的私有属性会静默丢失。
    protected ?string $swooleTaskMonitorId = null;
    protected array $swooleTaskMonitorContext = [];
    protected bool $swooleTaskMonitorTraceSampled = false;
    protected ?array $swooleTaskMonitorRejection = null;
    /** 仅通过 IPC 传输标量，不序列化采集器、闭包或 Redis 连接。 */
    protected ?array $swooleTaskExecutionContext = null;
    /** 默认关闭的标量检查点；子类可通过现有回执携带。 */
    protected ?array $swooleTaskWorkerBoundaryNs = null;
    /** 独立的本地诊断上下文；绝不序列化采集器或文件系统句柄。 */
    protected ?array $swooleTaskFirstTraceContext = null;
    /** 仅在首次追踪诊断开启时携带数量受限的单调时钟检查点。 */
    protected array $swooleTaskFirstTraceTransportPoints = [];
    /** 自定义 IPC 跳转丢失生产者上下文、工作进程在本地重建后为 true。 */
    protected bool $swooleTaskFirstTraceWorkerFallback = false;
    private static ?array $firstTraceBudget = null;

    /**
     * Swoole 调用的固定执行入口，维护监控生命周期而不允许子类绕过它。
     *
     * 先记录 started 并开始计时，再调用子类业务实现。异常优先于已标记的拒绝状态：异常
     * 会记录 failed 后原样抛出，供 Swoole 保持既有错误处理；无异常时才根据 rejection
     * 标记记录 rejected 或 finished。因此每次调用最多写入一个 worker 终态。
     */
    final public function handle()
    {
        if ($this->swooleTaskWorkerBoundaryNs !== null) {
            // 每次尝试均重置：框架重试不能复用更早的工作进程入口时间。
            $this->swooleTaskWorkerBoundaryNs = ['raw_handle_entry' => hrtime(true)];
        }
        // 在记录器自动加载前采集；限制启用后的记录数量，不采样消息。
        $firstTrace = null;
        try {
            // 部分自定义 Swoole IPC 路径可能仅序列化任务的公开负载，并
            // 丢弃受保护的诊断上下文。为工作进程入口重建
            // 有界本地追踪，以保持实际 Task 阶段可观测。该降级
            // 仅用于诊断，其 submitted_at_ns 刻意使用工作进程入口边界，
            // 绝不能解释为生产者到工作进程的排队延迟。
            $workerFallback = $this->swooleTaskFirstTraceContext === null;
            $this->prepareSwooleTaskFirstTrace();
            $this->swooleTaskFirstTraceWorkerFallback = $workerFallback
                && $this->swooleTaskFirstTraceContext !== null;
            $firstCall = $this->reserveFirstTrace();
            $firstEntry = $firstCall !== null ? [
                'wall_ns' => hrtime(true), 'usage' => function_exists('getrusage') ? getrusage() : null,
                'files' => count(get_included_files()), 'classes' => count(get_declared_classes()),
                'memory_bytes' => memory_get_usage(true),
            ] : null;
            if ($firstEntry !== null) {
                $firstEntry['sample_ns'] = hrtime(true) - $firstEntry['wall_ns'];
                $firstTrace = SwooleTaskFirstTrace::start($this->swooleTaskFirstTraceContext, $firstEntry, $firstCall,
                    $this->swooleTaskFirstTraceWorkerFallback, $this->swooleTaskFirstTraceTransportPoints);
                SwooleTaskFirstTrace::setCurrent($firstTrace);
            }
        } catch (Throwable) {
            // 即使传输中的诊断上下文格式错误，也不能阻止业务执行。
        }
        $entry = $this->swooleTaskExecutionContext !== null ? hrtime(true) : null;
        $diagnostics = $this->swooleTaskExecutionContext !== null
            ? SwooleTaskExecutionDiagnostics::start($this->swooleTaskExecutionContext['run_id']) : null;
        $durations = [];
        $outcome = 'finished';
        $monitorFailures = $diagnostics !== null ? SwooleTaskMonitor::recordFailureCount() : 0;
        $start = microtime(true);
        $startedAt = $diagnostics?->begin();
        $firstTrace?->checkpoint('before_monitor_started');
        if ($this->swooleTaskWorkerBoundaryNs !== null) {
            $this->swooleTaskWorkerBoundaryNs['before_monitor_started'] = hrtime(true);
        }
        SwooleTaskMonitor::recordStarted($this);
        if ($this->swooleTaskWorkerBoundaryNs !== null) {
            $this->swooleTaskWorkerBoundaryNs['after_monitor_started'] = hrtime(true);
        }
        $firstTrace?->checkpoint('before_business');
        $businessAt = $diagnostics?->begin();
        if ($startedAt !== null && $businessAt !== null) {
            $durations['monitor_started'] = $businessAt - $startedAt;
        }

        $sqlDiagnostics = null;
        try {
            $sqlDiagnostics = SwooleTaskSqlDiagnostics::begin(static::class);
        } catch (Throwable) {
            // Include autoload/call-boundary failures in the diagnostic fail-open guarantee.
        }
        try {
            $this->handleTask();
        } catch (Throwable $exception) {
            $outcome = 'failed';
            throw $exception;
        } finally {
            try {
                $sqlDiagnostics?->finish($outcome === 'failed');
            } catch (Throwable) {
                // Never replace the original business Throwable with an observation failure.
            }
            $firstTrace?->checkpoint('after_business');
            $terminalAt = $diagnostics?->begin();
            if ($businessAt !== null && $terminalAt !== null) {
                $durations['handle_task'] = $terminalAt - $businessAt;
            }
            // 保留原耗时边界：包括开始监控和业务处理，不包括终态监控。
            if ($outcome === 'failed') {
                SwooleTaskMonitor::recordFailed($this, $this->elapsedMs($start), $exception);
            } elseif ($this->swooleTaskMonitorRejection !== null) {
                $outcome = 'rejected';
                SwooleTaskMonitor::recordRejected($this, $this->elapsedMs($start),
                    $this->swooleTaskMonitorRejection['reason'], $this->swooleTaskMonitorRejection['context']);
            } else {
                SwooleTaskMonitor::recordFinished($this, $this->elapsedMs($start));
            }
            $firstTrace?->checkpoint('after_terminal');
            $firstTrace?->finish($outcome);
            SwooleTaskFirstTrace::setCurrent(null);
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
                    // 观测不能替换业务结果或其原始 Throwable。
                }
            }
            // 依赖完成状态的调度器仅在终态统计后继续推进。
            // 默认钩子为空，不改变普通 Task 传输。
            $this->afterMonitoredTaskTerminal($outcome);
        }
    }

    protected function afterMonitoredTaskTerminal(string $outcome): void {}

    /** 采用低成本的进程本地限额：后续消息不再枚举类和文件，也不再采样 rusage。 */
    private function reserveFirstTrace(): ?int
    {
        $context = $this->swooleTaskFirstTraceContext;
        if ($context === null || config('swoole_task_monitor.first_trace.enabled', false) !== true
            || !is_string($context['run_id'] ?? null)
            || config('swoole_task_monitor.first_trace.run_id') !== $context['run_id']
            || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $context['run_id']) !== 1
            || !is_int($context['submitted_at_ns'] ?? null) || $context['submitted_at_ns'] < 0
            || !in_array($context['channel'] ?? null, ['location', 'diy'], true)) {
            return null;
        }
        $pid = getmypid();
        if (self::$firstTraceBudget === null || self::$firstTraceBudget['pid'] !== $pid
            || self::$firstTraceBudget['run'] !== $context['run_id']) {
            self::$firstTraceBudget = ['pid' => $pid, 'run' => $context['run_id'], 'calls' => 0,
                'location' => 0, 'diy' => 0];
        }
        $call = ++self::$firstTraceBudget['calls'];
        $channel = $context['channel'];
        if (self::$firstTraceBudget[$channel] >= 2) {
            return null;
        }
        self::$firstTraceBudget[$channel]++;
        return $call;
    }

    /** 本地追踪独立于基于 Redis 的累计执行采集器。 */
    final public function prepareSwooleTaskFirstTrace(): void
    {
        try {
            if ($this->swooleTaskFirstTraceContext !== null
                || config('swoole_task_monitor.first_trace.enabled', false) !== true
                || !isset(SwooleTaskExecutionDiagnostics::CHANNELS[static::class])) {
                return;
            }
            $run = config('swoole_task_monitor.first_trace.run_id');
            if (SwooleTaskExecutionDiagnostics::validRun($run)) {
                $this->swooleTaskFirstTraceContext = ['run_id' => $run,
                    'channel' => SwooleTaskExecutionDiagnostics::CHANNELS[static::class],
                    'submitted_at_ns' => hrtime(true)];
            }
        } catch (Throwable) {
            // 诊断覆盖无效或缺失时，不改变投递行为。
        }
    }

    /** 生产者提交监控后、首次传输前立即调用。 */
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
            // 证据校验器会拒绝缺失的工作进程覆盖，不得用零耗时补齐。
        }
    }

    /** 为有界首次追踪探针记录固定、不含负载的单调时钟点。 */
    final public function recordSwooleTaskFirstTracePoint(string $name): void
    {
        if (!in_array($name, ['submitted', 'send_before', 'pipe_received', 'handle_entry'], true)
            || $this->swooleTaskFirstTraceContext === null) {
            return;
        }
        $this->swooleTaskFirstTraceTransportPoints[$name] = hrtime(true);
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
