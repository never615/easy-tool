<?php

namespace Mallto\Tool\Domain\SwooleTaskMonitor;

use Illuminate\Support\Facades\Redis;
use Throwable;

class SwooleTaskMonitorStore
{
    private const PREFIX = 'swoole_task_monitor';
    private const RETENTION_SECONDS = 172800;
    private const START_TTL_SECONDS = 3600;
    private const SAMPLE_LIMIT = 50;
    private const SLOW_TASK_MS = 100;

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

    public function recordDelivered(string $taskClass): void
    {
        $date = $this->date();
        $statsKey = $this->statsKey($date);
        $this->increment($statsKey, $taskClass, 'delivered');
        $this->touchMeta($date);
    }

    public function recordDeliverFailed(string $taskId, string $taskClass, string $reason, ?Throwable $exception = null): void
    {
        $date = $this->date();
        $statsKey = $this->statsKey($date);
        $this->increment($statsKey, $taskClass, 'deliver_failed');
        Redis::del($this->startKey($taskId));
        $this->recordErrorSample($date, $taskClass, 'deliver_failed', $reason, $exception);
        $this->touchMeta($date);
    }

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
            'recent_direct' => $this->decodeListRows($this->sampleKey($date, 'direct')),
        ];
    }

    public function reset(?string $date = null): void
    {
        $date = $date ?: $this->date();
        Redis::del(
            $this->statsKey($date),
            $this->metaKey($date),
            $this->runningKey($date),
            $this->sampleKey($date, 'errors'),
            $this->sampleKey($date, 'slow'),
            $this->sampleKey($date, 'drops'),
            $this->sampleKey($date, 'direct')
        );
    }

    private function startMeta(string $taskId): array
    {
        if ($taskId === '') {
            return [];
        }

        $raw = Redis::get($this->startKey($taskId));
        $decoded = json_decode((string)$raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function clearRuntimeState(string $date, string $taskId): void
    {
        Redis::hdel($this->runningKey($date), $taskId);
        Redis::del($this->startKey($taskId));
    }

    private function runningSet(string $date, string $taskId, array $payload): void
    {
        $key = $this->runningKey($date);
        Redis::hset($key, $taskId, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Redis::expire($key, self::RETENTION_SECONDS);
    }

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

    private function pushSample(string $key, array $payload): void
    {
        Redis::lpush($key, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Redis::ltrim($key, 0, self::SAMPLE_LIMIT - 1);
        Redis::expire($key, self::RETENTION_SECONDS);
    }

    private function increment(string $key, string $taskClass, string $metric, int $value = 1): void
    {
        Redis::hIncrBy($key, $this->field($taskClass, $metric), $value);
        Redis::expire($key, self::RETENTION_SECONDS);
    }

    private function recordMax(string $key, string $taskClass, string $metric, int $value): void
    {
        $field = $this->field($taskClass, $metric);
        $current = (int)Redis::hget($key, $field);
        if ($value > $current) {
            Redis::hset($key, $field, $value);
            Redis::expire($key, self::RETENTION_SECONDS);
        }
    }

    private function touchMeta(string $date): void
    {
        $key = $this->metaKey($date);
        Redis::hset($key, 'updated_at', time());
        Redis::hset($key, 'updated_at_text', date('Y-m-d H:i:s'));
        Redis::expire($key, self::RETENTION_SECONDS);
    }

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
            $failed = (int)($row['failed'] ?? 0);
            $started = (int)($row['started'] ?? 0);
            $delivered = (int)($row['delivered'] ?? 0);
            $durationCount = $finished + $failed;
            $durationTotal = (int)($row['duration_total_ms'] ?? 0);
            $waitTotal = (int)($row['wait_total_ms'] ?? 0);

            $row['pending'] = max(0, $delivered - $started);
            $row['running'] = max(0, $started - $finished - $failed);
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

    private function summaryFromRows(array $rows): array
    {
        $summary = [
            'submitted' => 0,
            'delivered' => 0,
            'deliver_failed' => 0,
            'dropped' => 0,
            'direct_handled' => 0,
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

    private function field(string $taskClass, string $metric): string
    {
        return $taskClass . '|' . $metric;
    }

    private function shortClassName(string $taskClass): string
    {
        $pos = strrpos($taskClass, '\\');

        return $pos === false ? $taskClass : substr($taskClass, $pos + 1);
    }

    private function statsKey(string $date): string
    {
        return self::PREFIX . ':stats:' . $date;
    }

    private function metaKey(string $date): string
    {
        return self::PREFIX . ':meta:' . $date;
    }

    private function startKey(string $taskId): string
    {
        return self::PREFIX . ':start:' . $taskId;
    }

    private function runningKey(string $date): string
    {
        return self::PREFIX . ':running:' . $date;
    }

    private function sampleKey(string $date, string $type): string
    {
        return self::PREFIX . ':sample:' . $type . ':' . $date;
    }

    private function date(): string
    {
        return date('Ymd');
    }

    private function nowMs(): int
    {
        return (int)floor(microtime(true) * 1000);
    }
}
