<?php

namespace Mallto\Tool\Domain\SwooleTaskMonitor;

use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Swoole Task 运输层监控的 Redis 读写存储。
 *
 * 按日保存各任务类的聚合统计（Hash）、管理页刷新元数据（Hash）、运行中 trace（Hash）
 * 和限长诊断样本（List）；trace 模式另以 task ID 保存 submitted 临时状态（String）。
 * SwooleTaskMonitor::safeRecord() 负责在上层吞掉 Redis 异常，本类刻意不自行恢复错误。
 *
 * 这些数据是无事务、尽力而为的诊断读模型，不应作为精确队列深度、审计日志或业务状态源。
 * 特别是最大值的读写并非原子操作，跨日执行、Redis 过期或重复事件都可能导致派生值偏差。
 */
class SwooleTaskMonitorStore
{
    /** Redis 键名前缀；可由构造器替换以隔离测试或不同部署域。 */
    private const PREFIX = 'swoole_task_monitor';
    /** 每日日桶的统计、元数据、运行态和样本保留两天。 */
    private const RETENTION_SECONDS = 172800;
    /** submitted 临时状态最多保留一小时，用于关联投递和 worker 开始时间。 */
    private const START_TTL_SECONDS = 3600;
    /** 每种诊断样本键只保留最新的五十项。 */
    private const SAMPLE_LIMIT = 50;
    /** 正常完成耗时达到该阈值时计为慢任务并保留样本。 */
    private const SLOW_TASK_MS = 100;
    /** 同一 PHP 进程对同一键最多每分钟重新设置一次过期时间。 */
    private const KEY_EXPIRE_INTERVAL_SECONDS = 60;
    /** 管理页更新元数据最多每秒写入一次。 */
    private const META_TOUCH_INTERVAL_SECONDS = 1;
    /** 相同错误或原因的样本在同一进程内最多每五秒记录一次。 */
    private const SAMPLE_THROTTLE_SECONDS = 5;

    /** @var array<string, int> 当前进程已刷新过期时间的键及时间戳。 */
    private static array $expireTouchedAt = [];
    /** @var array<string, int> 当前进程已触碰元数据的键及时间戳。 */
    private static array $metaTouchedAt = [];
    /** @var array<string, int> 当前进程已写入样本身份及时间戳。 */
    private static array $samplePushedAt = [];

    public function __construct(private readonly string $prefix = self::PREFIX)
    {
    }

    /**
     * Resolve the selected Redis connection; Laravel's manager reuses the connection.
     * A dedicated connection
     * keeps high-rate diagnostic counters away from the business queue/cache Redis.
     */
    private function redis()
    {
        $connection = config('swoole_task_monitor.redis_connection');

        return $connection ? Redis::connection($connection) : Redis::connection();
    }

    /**
     * 记录 trace 采样命中的 Task 已提交，并保存投递前临时状态。
     *
     * 除 submitted 外，负载大小会进入聚合统计；start 键保存投递时间、上下文和负载，供
     * recordStarted() 计算排队时长并填充 running 状态。该键尚不代表 Swoole 已接受任务，
     * 随后的投递失败会由 recordDeliverFailed() 删除。
     *
     * @param array<string, mixed> $context 投递点诊断上下文
     */
    public function recordSubmitted(string $taskId, string $taskClass, int $payloadBytes, array $context = []): void
    {
        $nowMs = $this->nowMs();
        $date = $this->date();
        $statsKey = $this->statsKey($date);

        $this->increment($statsKey, $taskClass, 'submitted');
        $this->increment($statsKey, $taskClass, 'payload_bytes_total', $payloadBytes);
        $this->recordMax($statsKey, $taskClass, 'payload_bytes_max', $payloadBytes);
        $this->touchMeta($date);

        $this->redis()->setex($this->startKey($taskId), self::START_TTL_SECONDS, json_encode([
            'task_id' => $taskId,
            'task_class' => $taskClass,
            'payload_bytes' => $payloadBytes,
            'context' => $context,
            'submitted_at_ms' => $nowMs,
            'submitted_at' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 仅为未采样 Task 增加 submitted 聚合计数。
     *
     * summary 模式不保存 task ID、上下文、负载或临时 start 状态，因而无法计算这一次
     * Task 的排队时间或展示运行明细。
     */
    public function recordSubmittedSummary(string $taskClass): void
    {
        $date = $this->date();
        $this->increment($this->statsKey($date), $taskClass, 'submitted');
        $this->touchMeta($date);
    }

    /**
     * 在 Task::deliver() 已成功返回后记录 delivered。
     *
     * submitted 表示开始尝试投递，delivered 才表示 LaravelS 接口未报错且未返回 false；
     * 该事件仍不表示 worker 已开始执行。
     */
    public function recordDelivered(string $taskClass): void
    {
        $date = $this->date();
        $statsKey = $this->statsKey($date);
        $this->increment($statsKey, $taskClass, 'delivered');
        $this->touchMeta($date);
    }

    /**
     * 记录调用 Task::deliver() 失败，并清理尚未被 worker 消费的 trace 临时状态。
     *
     * deliver_failed 是投递阶段结果而非 worker 终态。未采样 Task 没有 start 键，因此通过
     * hasTraceState 避免以空 ID 删除无关键；失败原因和异常摘要以限流样本形式保留。
     */
    public function recordDeliverFailed(
        string $taskId,
        string $taskClass,
        string $reason,
        ?Throwable $exception = null,
        bool $hasTraceState = true
    ): void {
        $date = $this->date();
        $statsKey = $this->statsKey($date);
        $this->increment($statsKey, $taskClass, 'deliver_failed');
        if ($hasTraceState && $taskId !== '') {
            $this->redis()->del($this->startKey($taskId));
        }
        $this->recordErrorSample($date, $taskClass, 'deliver_failed', $reason, $exception);
        $this->touchMeta($date);
    }

    /**
     * 记录调用侧决定丢弃、尚未进入 worker 的任务，并保留最近原因样本。
     *
     * dropped 与 delivered、started 和 Task 终态没有计数换算关系，例如队列满和上游解析
     * 失败均可使用此事件。
     *
     * @param array<string, mixed> $context 丢弃位置的诊断上下文
     */
    public function recordDropped(string $taskClass, string $reason, array $context = []): void
    {
        $date = $this->date();
        $statsKey = $this->statsKey($date);
        $this->increment($statsKey, $taskClass, 'dropped');
        $this->pushSample($this->sampleKey($date, 'drops'), [
            'task_class' => $taskClass,
            'reason' => $reason,
            'context' => $context,
            'created_at' => date('Y-m-d H:i:s'),
            'pid' => getmypid() ?: null,
        ]);
        $this->touchMeta($date);
    }

    /**
     * 记录调用侧限流导致的未投递任务。
     *
     * 单独统计是为了从非预期的 dropped 中区分预期流量保护策略。
     *
     * @param array<string, mixed> $context 限流键、超时时间等诊断上下文
     */
    public function recordRateLimited(string $taskClass, string $reason, array $context = []): void
    {
        $date = $this->date();
        $statsKey = $this->statsKey($date);
        $this->increment($statsKey, $taskClass, 'rate_limited');
        $this->pushSample($this->sampleKey($date, 'rate_limited'), [
            'task_class' => $taskClass,
            'reason' => $reason,
            'context' => $context,
            'created_at' => date('Y-m-d H:i:s'),
            'pid' => getmypid() ?: null,
        ]);
        $this->touchMeta($date);
    }

    /**
     * 记录当前进程直接处理完成、未经过 Swoole worker 的分支。
     *
     * 此事件不增加异步 Task 的 finished，以避免同步降级路径被误认为 worker 吞吐。
     *
     * @param array<string, mixed> $context 直接处理原因及诊断上下文
     */
    public function recordDirectHandled(string $taskClass, string $reason, array $context = []): void
    {
        $date = $this->date();
        $statsKey = $this->statsKey($date);
        $this->increment($statsKey, $taskClass, 'direct_handled');
        $this->pushSample($this->sampleKey($date, 'direct'), [
            'task_class' => $taskClass,
            'reason' => $reason,
            'context' => $context,
            'created_at' => date('Y-m-d H:i:s'),
            'pid' => getmypid() ?: null,
        ]);
        $this->touchMeta($date);
    }

    /**
     * 记录调用侧直接拒绝、未进入 worker 的分支。
     *
     * 样本 execution_mode 为 direct，用以区分 MonitoredSwooleTask 在 worker 内产生的
     * rejected 终态。
     *
     * @param array<string, mixed> $context 拒绝原因及诊断上下文
     */
    public function recordDirectRejected(string $taskClass, string $reason, array $context = []): void
    {
        $date = $this->date();
        $this->increment($this->statsKey($date), $taskClass, 'direct_rejected');
        $this->recordRejectionSample($date, $taskClass, $reason, 'direct', $context);
        $this->touchMeta($date);
    }

    /**
     * 仅为未采样 Task 增加 worker started 聚合计数。
     *
     * 由于没有 submitted 临时状态，summary 模式不会计算等待时间或创建 running 明细。
     */
    public function recordStartedSummary(string $taskClass): void
    {
        $date = $this->date();
        $this->increment($this->statsKey($date), $taskClass, 'started');
        $this->touchMeta($date);
    }

    /**
     * 记录 trace Task 已在 worker 开始执行，计算排队时长并建立运行态。
     *
     * submitted 临时状态过期、丢失或直接执行时，等待时间降为零但仍创建 running 条目。
     * 投递上下文先与运行时上下文合并，后者覆盖同名键；两阶段负载大小取较大值，以避免
     * Task 在反序列化后才补充的估算被遗漏。
     *
     * @param array<string, mixed> $context Task 实例携带的监控上下文
     */
    public function recordStarted(string $taskId, string $taskClass, int $payloadBytes, array $context = []): void
    {
        $date = $this->date();
        $nowMs = $this->nowMs();
        $startMeta = $this->startMeta($taskId);
        $submittedAtMs = (int)($startMeta['submitted_at_ms'] ?? 0);
        $waitMs = $submittedAtMs > 0 ? max(0, $nowMs - $submittedAtMs) : 0;
        $context = array_merge($startMeta['context'] ?? [], $context);
        $payloadBytes = max($payloadBytes, (int)($startMeta['payload_bytes'] ?? 0));
        $statsKey = $this->statsKey($date);

        $this->increment($statsKey, $taskClass, 'started');
        $this->increment($statsKey, $taskClass, 'wait_total_ms', $waitMs);
        $this->recordMax($statsKey, $taskClass, 'wait_max_ms', $waitMs);
        $this->runningSet($date, $taskId, [
            'task_id' => $taskId,
            'task_class' => $taskClass,
            'payload_bytes' => $payloadBytes,
            'context' => $context,
            'submitted_at_ms' => $submittedAtMs ?: null,
            'started_at_ms' => $nowMs,
            'started_at' => time(),
            'pid' => getmypid() ?: null,
        ]);
        $this->touchMeta($date);
    }

    /**
     * 记录未采样 Task 的正常返回及近似执行耗时。
     *
     * 达到慢任务阈值时只增加 slow_count；summary 模式没有 task ID 和上下文，不能保存
     * 可追溯的慢任务样本或最大负载值。
     */
    public function recordFinishedSummary(string $taskClass, int $durationMs): void
    {
        $date = $this->date();
        $statsKey = $this->statsKey($date);

        $this->increment($statsKey, $taskClass, 'finished');
        $this->increment($statsKey, $taskClass, 'duration_total_ms', $durationMs);
        if ($durationMs >= self::SLOW_TASK_MS) {
            $this->increment($statsKey, $taskClass, 'slow_count');
        }
        $this->touchMeta($date);
    }

    /**
     * 记录 trace Task 的正常完成，并清理其 start 与 running 临时状态。
     *
     * finished 代表任务方法正常返回；只有正常完成会计入慢任务阈值。慢任务样本为有限、
     * 按原因节流的诊断材料，不是完整的性能事件流。
     *
     * @param array<string, mixed> $context 用于慢任务样本的追踪上下文
     */
    public function recordFinished(
        string $taskId,
        string $taskClass,
        int $durationMs,
        int $payloadBytes,
        array $context = []
    ): void {
        $date = $this->date();
        $statsKey = $this->statsKey($date);

        $this->increment($statsKey, $taskClass, 'finished');
        $this->increment($statsKey, $taskClass, 'duration_total_ms', $durationMs);
        $this->recordMax($statsKey, $taskClass, 'duration_max_ms', $durationMs);
        $this->increment($statsKey, $taskClass, 'payload_bytes_total_runtime', $payloadBytes);
        $this->recordMax($statsKey, $taskClass, 'payload_bytes_max_runtime', $payloadBytes);

        if ($durationMs >= self::SLOW_TASK_MS) {
            $this->increment($statsKey, $taskClass, 'slow_count');
            $this->pushSample($this->sampleKey($date, 'slow'), [
                'task_id' => $taskId,
                'task_class' => $taskClass,
                'duration_ms' => $durationMs,
                'payload_bytes' => $payloadBytes,
                'context' => $context,
                'created_at' => date('Y-m-d H:i:s'),
                'pid' => getmypid() ?: null,
            ]);
        }

        $this->clearRuntimeState($date, $taskId);
        $this->touchMeta($date);
    }

    /**
     * 记录未采样 Task 在 worker 内的显式业务拒绝。
     *
     * 虽不保存 trace 运行态，仍保留有限的 rejection 样本以支持失败原因诊断；该终态不
     * 计入 slow_count，即使它的执行时间超过慢任务阈值。
     *
     * @param array<string, mixed> $context 拒绝分支上下文
     */
    public function recordRejectedSummary(
        string $taskClass,
        int $durationMs,
        string $reason,
        array $context = []
    ): void {
        $date = $this->date();
        $statsKey = $this->statsKey($date);

        $this->increment($statsKey, $taskClass, 'rejected');
        $this->increment($statsKey, $taskClass, 'duration_total_ms', $durationMs);
        $this->recordRejectionSample($date, $taskClass, $reason, 'task', $context + [
            'duration_ms' => $durationMs,
        ]);
        $this->touchMeta($date);
    }

    /**
     * 记录 trace Task 在 worker 内的显式业务拒绝并清理运行态。
     *
     * rejected 是 started 之后的互斥终态，不等同于调用侧 direct_rejected。拒绝样本会包含
     * task ID、耗时和负载，调用方上下文中的同名键优先于补充字段。
     *
     * @param array<string, mixed> $context 拒绝分支上下文
     */
    public function recordRejected(
        string $taskId,
        string $taskClass,
        int $durationMs,
        int $payloadBytes,
        string $reason,
        array $context = []
    ): void {
        $date = $this->date();
        $statsKey = $this->statsKey($date);

        $this->increment($statsKey, $taskClass, 'rejected');
        $this->increment($statsKey, $taskClass, 'duration_total_ms', $durationMs);
        $this->recordMax($statsKey, $taskClass, 'duration_max_ms', $durationMs);
        $this->increment($statsKey, $taskClass, 'payload_bytes_total_runtime', $payloadBytes);
        $this->recordMax($statsKey, $taskClass, 'payload_bytes_max_runtime', $payloadBytes);
        $this->recordRejectionSample($date, $taskClass, $reason, 'task', $context + [
            'task_id' => $taskId,
            'duration_ms' => $durationMs,
            'payload_bytes' => $payloadBytes,
        ]);
        $this->clearRuntimeState($date, $taskId);
        $this->touchMeta($date);
    }

    /**
     * 记录未采样 Task 在 worker 执行时抛出的异常。
     *
     * summary 模式仍会记录异常类、文件、行号和消息等有限样本，但没有关联 task ID 的
     * 运行态可供清理。
     */
    public function recordFailedSummary(string $taskClass, int $durationMs, Throwable $exception): void
    {
        $date = $this->date();
        $statsKey = $this->statsKey($date);

        $this->increment($statsKey, $taskClass, 'failed');
        $this->increment($statsKey, $taskClass, 'duration_total_ms', $durationMs);
        $this->recordErrorSample($date, $taskClass, 'runtime_failed', $exception->getMessage(), $exception, [
            'duration_ms' => $durationMs,
        ]);
        $this->touchMeta($date);
    }

    /**
     * 记录 trace Task 的异常终态，保存诊断摘要并清理运行态。
     *
     * Store 只写观测数据，既不吞掉也不包装原始异常；调用者仍将异常交回 Swoole 的错误
     * 处理流程。
     *
     * @param array<string, mixed> $context 失败 Task 的追踪上下文
     */
    public function recordFailed(
        string $taskId,
        string $taskClass,
        int $durationMs,
        int $payloadBytes,
        Throwable $exception,
        array $context = []
    ): void {
        $date = $this->date();
        $statsKey = $this->statsKey($date);

        $this->increment($statsKey, $taskClass, 'failed');
        $this->increment($statsKey, $taskClass, 'duration_total_ms', $durationMs);
        $this->recordMax($statsKey, $taskClass, 'duration_max_ms', $durationMs);
        $this->recordErrorSample($date, $taskClass, 'runtime_failed', $exception->getMessage(), $exception, [
            'task_id' => $taskId,
            'duration_ms' => $durationMs,
            'payload_bytes' => $payloadBytes,
            'context' => $context,
        ]);
        $this->clearRuntimeState($date, $taskId);
        $this->touchMeta($date);
    }

    /**
     * 读取某日日桶的管理端快照，并将原始 Hash 指标派生为按任务类的行。
     *
     * pending=max(delivered-started, 0)，running=max(started-finished-rejected-failed, 0)。
     * 这些是聚合计数推导值，不以 running Hash 为准，因重复事件、跨日终态或 Redis 过期而
     * 漂移时会被截断为零。JSON 损坏的运行态或样本条目会静默跳过，保证诊断页可继续显示。
     *
     * @return array<string, mixed>
     */
    public function snapshot(?string $date = null): array
    {
        $date = $date ?: $this->date();
        $stats = $this->redis()->hgetall($this->statsKey($date)) ?: [];
        $rows = $this->rowsFromStats($stats);
        $summary = $this->summaryFromRows($rows);

        return [
            'date' => $date,
            'meta' => $this->redis()->hgetall($this->metaKey($date)) ?: [],
            'summary' => $summary,
            'rows' => $rows,
            'running' => $this->decodeHashRows($this->redis()->hgetall($this->runningKey($date)) ?: []),
            'recent_errors' => $this->decodeListRows($this->sampleKey($date, 'errors')),
            'recent_slow' => $this->decodeListRows($this->sampleKey($date, 'slow')),
            'recent_drops' => $this->decodeListRows($this->sampleKey($date, 'drops')),
            'recent_rate_limited' => $this->decodeListRows($this->sampleKey($date, 'rate_limited')),
            'recent_direct' => $this->decodeListRows($this->sampleKey($date, 'direct')),
            'recent_rejections' => $this->decodeListRows($this->sampleKey($date, 'rejections')),
        ];
    }

    /**
     * 删除指定日期的聚合、元数据、运行态和所有诊断样本。
     *
     * reset 不会删除 task ID 维度的 start 键，因此已提交但尚未开始的 trace Task 仍可能在
     * 随后进入 worker 时建立关联。静态节流缓存只存在当前 PHP/Swoole 进程，其他常驻
     * worker 的缓存不受影响。
     */
    public function reset(?string $date = null): void
    {
        $date = $date ?: $this->date();
        $keys = [
            $this->statsKey($date),
            $this->metaKey($date),
            $this->runningKey($date),
            $this->sampleKey($date, 'errors'),
            $this->sampleKey($date, 'slow'),
            $this->sampleKey($date, 'drops'),
            $this->sampleKey($date, 'rate_limited'),
            $this->sampleKey($date, 'direct'),
            $this->sampleKey($date, 'rejections'),
        ];

        $this->redis()->del(...$keys);

        foreach ($keys as $key) {
            unset(self::$expireTouchedAt[$key], self::$metaTouchedAt[$key]);
        }

        foreach (array_keys(self::$samplePushedAt) as $identity) {
            foreach ($keys as $key) {
                if (str_starts_with($identity, $key . '|')) {
                    unset(self::$samplePushedAt[$identity]);
                    break;
                }
            }
        }
    }

    /**
     * 读取 submitted 阶段保存的 trace 临时状态。
     *
     * 空 ID、Redis 键过期、缺失或 JSON 不是对象时一律返回空数组；调用方据此将等待时间
     * 视为零，而不会使已开始的 Task 失去 started 记录。
     *
     * @return array<string, mixed>
     */
    private function startMeta(string $taskId): array
    {
        if ($taskId === '') {
            return [];
        }

        $raw = $this->redis()->get($this->startKey($taskId));
        $decoded = json_decode((string)$raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 删除 trace Task 的运行中 Hash 条目及 submitted 临时状态。
     *
     * 只应由 finished、rejected 和 failed 等 worker 终态调用；投递失败只有 start 状态，
     * 使用 recordDeliverFailed() 单独清理。
     */
    private function clearRuntimeState(string $date, string $taskId): void
    {
        $this->redis()->hdel($this->runningKey($date), $taskId);
        $this->redis()->del($this->startKey($taskId));
    }

    /**
     * 将已开始的 trace Task 写入按日运行态 Hash，并确保该日桶会过期。
     *
     * Hash field 为 task ID，value 为 JSON 诊断负载；该 Hash 用于展示活跃实例，不参与
     * snapshot() 的 running 派生计数，避免状态不完整时造成双重口径。
     *
     * @param array<string, mixed> $payload 运行中 Task 的诊断字段
     */
    private function runningSet(string $date, string $taskId, array $payload): void
    {
        $key = $this->runningKey($date);
        $this->redis()->hset($key, $taskId, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->expireKey($key);
    }

    /**
     * 构造投递或运行失败的有限诊断样本。
     *
     * 仅保存异常类别、文件、行号与消息等摘要，不保存完整堆栈；具体是否写入仍由
     * pushSample() 的按身份节流和样本上限决定。
     *
     * @param array<string, mixed> $extra 阶段特有的诊断字段
     */
    private function recordErrorSample(
        string $date,
        string $taskClass,
        string $stage,
        string $reason,
        ?Throwable $exception = null,
        array $extra = []
    ): void {
        $this->pushSample($this->sampleKey($date, 'errors'), array_merge([
            'task_class' => $taskClass,
            'stage' => $stage,
            'reason' => $reason,
            'exception' => $exception ? get_class($exception) : null,
            'file' => $exception ? $exception->getFile() : null,
            'line' => $exception ? $exception->getLine() : null,
            'created_at' => date('Y-m-d H:i:s'),
            'pid' => getmypid() ?: null,
        ], $extra));
    }

    /**
     * 构造显式拒绝的有限诊断样本。
     *
     * executionMode 为 task 或 direct，分别表示 worker 内终态和调用侧直接拒绝，防止
     * 管理端将两类原因混淆。
     *
     * @param array<string, mixed> $context 拒绝分支上下文
     */
    private function recordRejectionSample(
        string $date,
        string $taskClass,
        string $reason,
        string $executionMode,
        array $context
    ): void {
        $this->pushSample($this->sampleKey($date, 'rejections'), [
            'task_class' => $taskClass,
            'reason' => $reason,
            'execution_mode' => $executionMode,
            'context' => $context,
            'created_at' => date('Y-m-d H:i:s'),
            'pid' => getmypid() ?: null,
        ]);
    }

    /**
     * 将一项诊断样本推到列表头部，截断为固定上限并设置日桶过期时间。
     *
     * 同一身份短时间内被 shouldPushSample() 去重；因此样本用于定位近期代表性问题，
     * 不是逐事件的可靠审计记录。
     *
     * @param array<string, mixed> $payload 待 JSON 编码的样本内容
     */
    private function pushSample(string $key, array $payload): void
    {
        if (!$this->shouldPushSample($key, $payload)) {
            return;
        }

        $this->redis()->lpush($key, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->redis()->ltrim($key, 0, self::SAMPLE_LIMIT - 1);
        $this->expireKey($key);
    }

    /**
     * 对指定任务类的聚合指标执行 Redis Hash 原子加法。
     *
     * field 使用“完整类名|指标名”编码，允许多个任务类共享同一日桶；成功变更后按本进程
     * 节流策略刷新键的保留期。
     */
    private function increment(string $key, string $taskClass, string $metric, int $value = 1): void
    {
        $this->redis()->hIncrBy($key, $this->field($taskClass, $metric), $value);
        $this->expireKey($key);
    }

    /**
     * 尝试更新指定任务类指标的最大值。
     *
     * HGET 后 HSET 不是 Redis 原子比较更新：并发写入时最大值可能被较小的旧读数覆盖。
     * 该限制对诊断展示可接受；此方法不应被用作精确峰值或告警依据。
     */
    private function recordMax(string $key, string $taskClass, string $metric, int $value): void
    {
        $field = $this->field($taskClass, $metric);
        $current = (int)$this->redis()->hget($key, $field);
        if ($value > $current) {
            $this->redis()->hset($key, $field, $value);
            $this->expireKey($key);
        }
    }

    /**
     * 更新管理端读取的最后变更时间，并限制写入频率。
     *
     * 节流仅在当前进程有效，多 worker 仍可同时更新。updated_at 是秒级近似刷新标识，
     * 不代表任何单一 Task 的严格事件顺序。
     */
    private function touchMeta(string $date): void
    {
        $key = $this->metaKey($date);
        $now = time();
        if (($now - (self::$metaTouchedAt[$key] ?? 0)) < self::META_TOUCH_INTERVAL_SECONDS) {
            return;
        }

        self::$metaTouchedAt[$key] = $now;
        $this->redis()->hset($key, 'updated_at', time());
        $this->redis()->hset($key, 'updated_at_text', date('Y-m-d H:i:s'));
        $this->expireKey($key);
    }

    /**
     * 为日桶键设置保留期，并避免每次计数都发送 EXPIRE。
     *
     * 时间戳缓存为进程内静态变量：进程重启、多个 worker 或 Redis 键被外部删除后，实际
     * 过期时间可能略早或略晚于首次写入后的固定两天。
     */
    private function expireKey(string $key): void
    {
        $now = time();
        if (($now - (self::$expireTouchedAt[$key] ?? 0)) < self::KEY_EXPIRE_INTERVAL_SECONDS) {
            return;
        }

        self::$expireTouchedAt[$key] = $now;
        $this->redis()->expire($key, self::RETENTION_SECONDS);
    }

    /**
     * 判断当前样本身份是否通过当前进程内的短时间去重。
     *
     * 身份由键、任务类、阶段、原因和执行模式组成，不含 task ID 或上下文。不同 worker
     * 各自维护缓存，故集群环境仍可能出现同类重复样本。
     *
     * @param array<string, mixed> $payload 样本内容
     */
    private function shouldPushSample(string $key, array $payload): bool
    {
        $identity = implode('|', [
            $key,
            (string)($payload['task_class'] ?? ''),
            (string)($payload['stage'] ?? ''),
            (string)($payload['reason'] ?? ''),
            (string)($payload['execution_mode'] ?? ''),
        ]);
        $now = time();
        if (($now - (self::$samplePushedAt[$identity] ?? 0)) < self::SAMPLE_THROTTLE_SECONDS) {
            return false;
        }

        self::$samplePushedAt[$identity] = $now;

        return true;
    }

    /**
     * 将“任务类|指标”格式的 Redis Hash 转为按任务类展示的统计行。
     *
     * duration_avg_ms 的分母是 finished、rejected 与 failed 之和；wait_avg_ms 的分母是
     * started。结果按运行中、待执行、失败、已投递的优先级排序，便于管理端首先展示需要
     * 关注的任务类型。
     *
     * @param array<string, mixed> $stats Redis Hash 原始字段
     * @return array<int, array<string, mixed>>
     */
    private function rowsFromStats(array $stats): array
    {
        $rows = [];
        foreach ($stats as $field => $value) {
            $parts = explode('|', (string)$field, 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$taskClass, $metric] = $parts;
            if (!isset($rows[$taskClass])) {
                $rows[$taskClass] = [
                    'task_class' => $taskClass,
                    'short_name' => $this->shortClassName($taskClass),
                ];
            }

            $rows[$taskClass][$metric] = is_numeric($value) ? (int)$value : $value;
        }

        foreach ($rows as $taskClass => $row) {
            $finished = (int)($row['finished'] ?? 0);
            $rejected = (int)($row['rejected'] ?? 0);
            $failed = (int)($row['failed'] ?? 0);
            $started = (int)($row['started'] ?? 0);
            $delivered = (int)($row['delivered'] ?? 0);
            $durationCount = $finished + $rejected + $failed;
            $durationTotal = (int)($row['duration_total_ms'] ?? 0);
            $waitTotal = (int)($row['wait_total_ms'] ?? 0);

            $row['pending'] = max(0, $delivered - $started);
            $row['running'] = max(0, $started - $finished - $rejected - $failed);
            $row['duration_avg_ms'] = $durationCount > 0 ? (int)round($durationTotal / $durationCount) : 0;
            $row['wait_avg_ms'] = $started > 0 ? (int)round($waitTotal / $started) : 0;
            $rows[$taskClass] = $row;
        }

        uasort($rows, function (array $left, array $right) {
            return ((int)($right['running'] ?? 0) <=> (int)($left['running'] ?? 0))
                ?: ((int)($right['pending'] ?? 0) <=> (int)($left['pending'] ?? 0))
                ?: ((int)($right['failed'] ?? 0) <=> (int)($left['failed'] ?? 0))
                ?: ((int)($right['delivered'] ?? 0) <=> (int)($left['delivered'] ?? 0));
        });

        return array_values($rows);
    }

    /**
     * 汇总各任务类行的核心计数，形成管理端顶部总览。
     *
     * 只汇总预定义的运输层指标，负载、耗时最大值和平均值保留在任务类行中，避免错误地
     * 将不同类型 Task 的统计口径混合。
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function summaryFromRows(array $rows): array
    {
        $summary = [
            'submitted' => 0,
            'delivered' => 0,
            'deliver_failed' => 0,
            'dropped' => 0,
            'rate_limited' => 0,
            'direct_handled' => 0,
            'rejected' => 0,
            'direct_rejected' => 0,
            'started' => 0,
            'finished' => 0,
            'failed' => 0,
            'running' => 0,
            'pending' => 0,
            'slow_count' => 0,
        ];

        foreach ($rows as $row) {
            foreach ($summary as $metric => $value) {
                $summary[$metric] += (int)($row[$metric] ?? 0);
            }
        }

        return $summary;
    }

    /**
     * 读取样本 List 并解码为管理端可展示的 JSON 行。
     *
     * Redis 中残留的损坏、旧格式或非对象 JSON 条目会被跳过，不影响同一列表内其余样本。
     *
     * @return array<int, array<string, mixed>>
     */
    private function decodeListRows(string $key): array
    {
        $items = $this->redis()->lrange($key, 0, self::SAMPLE_LIMIT - 1) ?: [];
        $rows = [];
        foreach ($items as $item) {
            $decoded = json_decode((string)$item, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * 解码运行态 Hash，按开始时间倒序并限制返回数量。
     *
     * 当 JSON 中未包含 task_id 时使用 Hash field 兜底，兼容部分写入或旧格式数据；无效
     * JSON 条目会被忽略。
     *
     * @param array<string, mixed> $hash
     * @return array<int, array<string, mixed>>
     */
    private function decodeHashRows(array $hash): array
    {
        $rows = [];
        foreach ($hash as $id => $json) {
            $decoded = json_decode((string)$json, true);
            if (is_array($decoded)) {
                $decoded['task_id'] = $decoded['task_id'] ?? $id;
                $rows[] = $decoded;
            }
        }

        usort($rows, fn(array $left, array $right) => ((int)($right['started_at_ms'] ?? 0)) <=> ((int)($left['started_at_ms'] ?? 0)));

        return array_slice($rows, 0, self::SAMPLE_LIMIT);
    }

    /**
     * 生成统计 Hash 的字段名，将任务类与指标放入同一日桶。
     */
    private function field(string $taskClass, string $metric): string
    {
        return $taskClass . '|' . $metric;
    }

    /**
     * 从完整命名空间类名提取管理端展示用短类名。
     */
    private function shortClassName(string $taskClass): string
    {
        $pos = strrpos($taskClass, '\\');

        return $pos === false ? $taskClass : substr($taskClass, $pos + 1);
    }

    /**
     * 返回指定日期的聚合指标 Hash 键。
     */
    private function statsKey(string $date): string
    {
        return $this->prefix . ':stats:' . $date;
    }

    /**
     * 返回指定日期的管理端刷新元数据 Hash 键。
     */
    private function metaKey(string $date): string
    {
        return $this->prefix . ':meta:' . $date;
    }

    /**
     * 返回 task ID 维度的 submitted 临时状态 String 键。
     *
     * 此键不按日分桶，依赖一小时 TTL 自动清理。
     */
    private function startKey(string $taskId): string
    {
        return $this->prefix . ':start:' . $taskId;
    }

    /**
     * 返回指定日期的 trace 运行态 Hash 键。
     */
    private function runningKey(string $date): string
    {
        return $this->prefix . ':running:' . $date;
    }

    /**
     * 返回指定日期、指定类别的限长诊断样本 List 键。
     */
    private function sampleKey(string $date, string $type): string
    {
        return $this->prefix . ':sample:' . $type . ':' . $date;
    }

    /**
     * 以应用进程当前时区返回日桶标识。
     *
     * 任务跨越零点时提交与终态可能落在不同日桶，这是日维度观测的既有限制。
     */
    private function date(): string
    {
        return date('Ymd');
    }

    /**
     * 返回当前墙钟毫秒时间，用于计算 trace Task 的近似排队时长。
     *
     * 该值受系统时钟调整影响；负差会在 recordStarted() 中钳制为零。
     */
    private function nowMs(): int
    {
        return (int)floor(microtime(true) * 1000);
    }
}
