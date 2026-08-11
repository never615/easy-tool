<?php

namespace Mallto\Tool\Domain\SwooleTaskMonitor;

use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * SwooleTaskMonitorStore：Swoole Task 生命周期监控组件。
 *
 * Redis 中分别保存最近事件、聚合统计与可选追踪样本。写入失败必须被上层吞掉，
 * 因为监控只提供可观测性，绝不能影响实际 Swoole Task 的投递和执行。
 */
class SwooleTaskMonitorStore
{
    private const PREFIX = 'swoole_task_monitor';
    private const RETENTION_SECONDS = 172800;
    private const START_TTL_SECONDS = 3600;
    private const SAMPLE_LIMIT = 50;
    private const SLOW_TASK_MS = 100;
    private const KEY_EXPIRE_INTERVAL_SECONDS = 60;
    private const META_TOUCH_INTERVAL_SECONDS = 1;
    private const SAMPLE_THROTTLE_SECONDS = 5;

    private static array $expireTouchedAt = [];
    private static array $metaTouchedAt = [];
    private static array $samplePushedAt = [];

    public function __construct(private readonly string $prefix = self::PREFIX)
    {
    }

    /**
     * 记录一次 Task 提交事件，并按采样策略决定是否保留追踪详情。
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

        Redis::setex($this->startKey($taskId), self::START_TTL_SECONDS, json_encode([
            'task_id' => $taskId,
            'task_class' => $taskClass,
            'payload_bytes' => $payloadBytes,
            'context' => $context,
            'submitted_at_ms' => $nowMs,
            'submitted_at' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 处理「recordSubmittedSummary」相关的内部协作步骤。
     */
    public function recordSubmittedSummary(string $taskClass): void
    {
        $date = $this->date();
        $this->increment($this->statsKey($date), $taskClass, 'submitted');
        $this->touchMeta($date);
    }

    /**
     * 更新已投递状态，使提交、投递和执行阶段可在诊断页区分。
     */
    public function recordDelivered(string $taskClass): void
    {
        $date = $this->date();
        $statsKey = $this->statsKey($date);
        $this->increment($statsKey, $taskClass, 'delivered');
        $this->touchMeta($date);
    }

    /**
     * 处理「recordDeliverFailed」相关的内部协作步骤。
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
            Redis::del($this->startKey($taskId));
        }
        $this->recordErrorSample($date, $taskClass, 'deliver_failed', $reason, $exception);
        $this->touchMeta($date);
    }

    /**
     * 处理「recordDropped」相关的内部协作步骤。
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
     * 处理「recordRateLimited」相关的内部协作步骤。
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
     * 处理「recordDirectHandled」相关的内部协作步骤。
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
     * 处理「recordDirectRejected」相关的内部协作步骤。
     */
    public function recordDirectRejected(string $taskClass, string $reason, array $context = []): void
    {
        $date = $this->date();
        $this->increment($this->statsKey($date), $taskClass, 'direct_rejected');
        $this->recordRejectionSample($date, $taskClass, $reason, 'direct', $context);
        $this->touchMeta($date);
    }

    /**
     * 处理「recordStartedSummary」相关的内部协作步骤。
     */
    public function recordStartedSummary(string $taskClass): void
    {
        $date = $this->date();
        $this->increment($this->statsKey($date), $taskClass, 'started');
        $this->touchMeta($date);
    }

    /**
     * 记录 worker 开始执行的时间，用于后续计算排队和执行耗时。
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
     * 处理「recordFinishedSummary」相关的内部协作步骤。
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
     * 写入成功完成状态并汇总耗时、负载与追踪信息。
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
     * 处理「recordRejectedSummary」相关的内部协作步骤。
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
     * 处理「recordRejected」相关的内部协作步骤。
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
     * 处理「recordFailedSummary」相关的内部协作步骤。
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
     * 写入失败状态及可安全展示的异常摘要，不改变原始任务异常传播。
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
     * 处理「snapshot」相关的内部协作步骤。
     */
    public function snapshot(?string $date = null): array
    {
        $date = $date ?: $this->date();
        $stats = Redis::hgetall($this->statsKey($date)) ?: [];
        $rows = $this->rowsFromStats($stats);
        $summary = $this->summaryFromRows($rows);

        return [
            'date' => $date,
            'meta' => Redis::hgetall($this->metaKey($date)) ?: [],
            'summary' => $summary,
            'rows' => $rows,
            'running' => $this->decodeHashRows(Redis::hgetall($this->runningKey($date)) ?: []),
            'recent_errors' => $this->decodeListRows($this->sampleKey($date, 'errors')),
            'recent_slow' => $this->decodeListRows($this->sampleKey($date, 'slow')),
            'recent_drops' => $this->decodeListRows($this->sampleKey($date, 'drops')),
            'recent_rate_limited' => $this->decodeListRows($this->sampleKey($date, 'rate_limited')),
            'recent_direct' => $this->decodeListRows($this->sampleKey($date, 'direct')),
            'recent_rejections' => $this->decodeListRows($this->sampleKey($date, 'rejections')),
        ];
    }

    /**
     * 处理「reset」相关的内部协作步骤。
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

        Redis::del(...$keys);

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
     * 处理「startMeta」相关的内部协作步骤。
     */
    private function startMeta(string $taskId): array
    {
        if ($taskId === '') {
            return [];
        }

        $raw = Redis::get($this->startKey($taskId));
        $decoded = json_decode((string)$raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 处理「clearRuntimeState」相关的内部协作步骤。
     */
    private function clearRuntimeState(string $date, string $taskId): void
    {
        Redis::hdel($this->runningKey($date), $taskId);
        Redis::del($this->startKey($taskId));
    }

    /**
     * 处理「runningSet」相关的内部协作步骤。
     */
    private function runningSet(string $date, string $taskId, array $payload): void
    {
        $key = $this->runningKey($date);
        Redis::hset($key, $taskId, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->expireKey($key);
    }

    /**
     * 处理「recordErrorSample」相关的内部协作步骤。
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
     * 处理「recordRejectionSample」相关的内部协作步骤。
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
     * 处理「pushSample」相关的内部协作步骤。
     */
    private function pushSample(string $key, array $payload): void
    {
        if (!$this->shouldPushSample($key, $payload)) {
            return;
        }

        Redis::lpush($key, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Redis::ltrim($key, 0, self::SAMPLE_LIMIT - 1);
        $this->expireKey($key);
    }

    /**
     * 处理「increment」相关的内部协作步骤。
     */
    private function increment(string $key, string $taskClass, string $metric, int $value = 1): void
    {
        Redis::hIncrBy($key, $this->field($taskClass, $metric), $value);
        $this->expireKey($key);
    }

    /**
     * 处理「recordMax」相关的内部协作步骤。
     */
    private function recordMax(string $key, string $taskClass, string $metric, int $value): void
    {
        $field = $this->field($taskClass, $metric);
        $current = (int)Redis::hget($key, $field);
        if ($value > $current) {
            Redis::hset($key, $field, $value);
            $this->expireKey($key);
        }
    }

    /**
     * 处理「touchMeta」相关的内部协作步骤。
     */
    private function touchMeta(string $date): void
    {
        $key = $this->metaKey($date);
        $now = time();
        if (($now - (self::$metaTouchedAt[$key] ?? 0)) < self::META_TOUCH_INTERVAL_SECONDS) {
            return;
        }

        self::$metaTouchedAt[$key] = $now;
        Redis::hset($key, 'updated_at', time());
        Redis::hset($key, 'updated_at_text', date('Y-m-d H:i:s'));
        $this->expireKey($key);
    }

    /**
     * 处理「expireKey」相关的内部协作步骤。
     */
    private function expireKey(string $key): void
    {
        $now = time();
        if (($now - (self::$expireTouchedAt[$key] ?? 0)) < self::KEY_EXPIRE_INTERVAL_SECONDS) {
            return;
        }

        self::$expireTouchedAt[$key] = $now;
        Redis::expire($key, self::RETENTION_SECONDS);
    }

    /**
     * 处理「shouldPushSample」相关的内部协作步骤。
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
     * 处理「rowsFromStats」相关的内部协作步骤。
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
     * 处理「summaryFromRows」相关的内部协作步骤。
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
     * 处理「decodeListRows」相关的内部协作步骤。
     */
    private function decodeListRows(string $key): array
    {
        $items = Redis::lrange($key, 0, self::SAMPLE_LIMIT - 1) ?: [];
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
     * 处理「decodeHashRows」相关的内部协作步骤。
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
     * 处理「field」相关的内部协作步骤。
     */
    private function field(string $taskClass, string $metric): string
    {
        return $taskClass . '|' . $metric;
    }

    /**
     * 处理「shortClassName」相关的内部协作步骤。
     */
    private function shortClassName(string $taskClass): string
    {
        $pos = strrpos($taskClass, '\\');

        return $pos === false ? $taskClass : substr($taskClass, $pos + 1);
    }

    /**
     * 处理「statsKey」相关的内部协作步骤。
     */
    private function statsKey(string $date): string
    {
        return $this->prefix . ':stats:' . $date;
    }

    /**
     * 处理「metaKey」相关的内部协作步骤。
     */
    private function metaKey(string $date): string
    {
        return $this->prefix . ':meta:' . $date;
    }

    /**
     * 处理「startKey」相关的内部协作步骤。
     */
    private function startKey(string $taskId): string
    {
        return $this->prefix . ':start:' . $taskId;
    }

    /**
     * 处理「runningKey」相关的内部协作步骤。
     */
    private function runningKey(string $date): string
    {
        return $this->prefix . ':running:' . $date;
    }

    /**
     * 处理「sampleKey」相关的内部协作步骤。
     */
    private function sampleKey(string $date, string $type): string
    {
        return $this->prefix . ':sample:' . $type . ':' . $date;
    }

    /**
     * 处理「date」相关的内部协作步骤。
     */
    private function date(): string
    {
        return date('Ymd');
    }

    /**
     * 处理「nowMs」相关的内部协作步骤。
     */
    private function nowMs(): int
    {
        return (int)floor(microtime(true) * 1000);
    }
}
