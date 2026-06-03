<?php

namespace Mallto\Tool\Domain\QueueDiagnostic;

use Illuminate\Support\Facades\Redis;

class QueueDiagnosticRedisStore
{
    public function __construct(private readonly QueueDiagnosticConfig $config)
    {
    }

    public function recordStarted(array $meta): void
    {
        $uuid = (string)($meta['uuid'] ?? '');
        if ($uuid === '') {
            return;
        }

        $ttl = $this->startTtlSeconds();
        Redis::setex($this->config->startKey($uuid), $ttl, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function recordFinished(array $meta, string $status, ?array $exception = null): void
    {
        $nowMs = $this->nowMs();
        $startMeta = $this->pullStartMeta((string)($meta['uuid'] ?? ''));
        $merged = array_merge($startMeta, $meta);
        $durationMs = $this->durationMs($startMeta, $nowMs);
        $payloadBytes = max(0, (int)($merged['payload_bytes'] ?? 0));
        $jobName = (string)($merged['name'] ?? 'unknown');
        $queue = (string)($merged['queue'] ?? 'unknown');
        $windowStart = $this->config->windowStart((int)floor($nowMs / 1000));

        $this->incrementWindow($windowStart, $jobName, $queue, $payloadBytes, $durationMs, $status, $exception, $merged);
    }

    public function windowSnapshot(?int $windowStart = null): array
    {
        $windowStart = $windowStart ?: $this->config->windowStart();
        $limit = $this->config->topLimit();

        return [
            'window_started_at' => $windowStart,
            'meta' => Redis::hgetall($this->config->windowKey($windowStart, 'meta')) ?: [],
            'events' => Redis::hgetall($this->config->windowKey($windowStart, 'events')) ?: [],
            'queues' => Redis::hgetall($this->config->windowKey($windowStart, 'queues')) ?: [],
            'redis_memory' => Redis::hgetall($this->config->windowKey($windowStart, 'redis')) ?: [],
            'keyspace' => Redis::hgetall($this->config->windowKey($windowStart, 'keyspace')) ?: [],
            'queue_sizes' => Redis::hgetall($this->config->windowKey($windowStart, 'queue_sizes')) ?: [],
            'anomaly' => Redis::hgetall($this->config->windowKey($windowStart, 'anomaly')) ?: [],
            'jobs' => $this->zsetTop($this->config->windowKey($windowStart, 'jobs'), $limit),
            'sources' => $this->zsetTop($this->config->windowKey($windowStart, 'sources'), $limit),
            'source_groups' => $this->zsetTop($this->config->windowKey($windowStart, 'source_groups'), $limit),
            'payload_jobs' => $this->zsetTop($this->config->windowKey($windowStart, 'payload'), $limit),
            'slow_jobs' => $this->zsetTop($this->config->windowKey($windowStart, 'slow'), $limit),
            'large_payload_jobs' => $this->zsetTop($this->config->windowKey($windowStart, 'large_payload'), $limit),
            'failed_jobs' => $this->zsetTop($this->config->windowKey($windowStart, 'failures'), $limit),
            'duration' => Redis::hgetall($this->config->windowKey($windowStart, 'duration')) ?: [],
            'last_event' => $this->decodeJson((string)Redis::get($this->config->windowKey($windowStart, 'last_event'))),
        ];
    }

    public function recordResourceSnapshot(int $windowStart, array $redisMemory, array $keyspace, array $queueSizes, array $anomaly): void
    {
        $keys = [
            $this->config->windowKey($windowStart, 'meta'),
            $this->config->windowKey($windowStart, 'redis'),
            $this->config->windowKey($windowStart, 'keyspace'),
            $this->config->windowKey($windowStart, 'queue_sizes'),
            $this->config->windowKey($windowStart, 'anomaly'),
        ];

        Redis::hset($keys[0], 'window_started_at', $windowStart);
        Redis::hset($keys[0], 'snapshot_updated_at', time());
        $this->writeHash($keys[1], $redisMemory);
        $this->writeHash($keys[2], $keyspace);
        $this->writeHash($keys[3], $queueSizes);
        $this->writeHash($keys[4], [
            'level' => $anomaly['level'] ?? '',
            'reasons' => json_encode($anomaly['reasons'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'checked_at' => $anomaly['checked_at'] ?? time(),
        ]);

        foreach ($keys as $key) {
            Redis::expire($key, $this->config->retentionSeconds());
        }
    }

    private function incrementWindow(
        int $windowStart,
        string $jobName,
        string $queue,
        int $payloadBytes,
        int $durationMs,
        string $status,
        ?array $exception,
        array $meta
    ): void {
        $keys = [
            'meta' => $this->config->windowKey($windowStart, 'meta'),
            'events' => $this->config->windowKey($windowStart, 'events'),
            'queues' => $this->config->windowKey($windowStart, 'queues'),
            'jobs' => $this->config->windowKey($windowStart, 'jobs'),
            'sources' => $this->config->windowKey($windowStart, 'sources'),
            'source_groups' => $this->config->windowKey($windowStart, 'source_groups'),
            'payload' => $this->config->windowKey($windowStart, 'payload'),
            'duration' => $this->config->windowKey($windowStart, 'duration'),
            'slow' => $this->config->windowKey($windowStart, 'slow'),
            'large_payload' => $this->config->windowKey($windowStart, 'large_payload'),
            'failures' => $this->config->windowKey($windowStart, 'failures'),
            'last_event' => $this->config->windowKey($windowStart, 'last_event'),
        ];

        Redis::hset($keys['meta'], 'window_started_at', $windowStart);
        Redis::hset($keys['meta'], 'updated_at', time());
        Redis::hIncrBy($keys['events'], 'total', 1);
        Redis::hIncrBy($keys['events'], $status, 1);
        Redis::hIncrBy($keys['queues'], $queue, 1);
        Redis::zIncrBy($keys['jobs'], 1, $jobName);
        $sourceLabel = $this->sourceLabel($meta['diagnostic_context'] ?? []);
        if ($sourceLabel !== '') {
            Redis::zIncrBy($keys['sources'], 1, $sourceLabel);
        }
        $sourceGroupLabel = $this->sourceGroupLabel($meta['diagnostic_context'] ?? []);
        if ($sourceGroupLabel !== '') {
            Redis::zIncrBy($keys['source_groups'], 1, $sourceGroupLabel);
        }

        Redis::zIncrBy($keys['payload'], $payloadBytes, $jobName);
        Redis::hIncrBy($keys['duration'], $jobName . ':count', 1);
        Redis::hIncrBy($keys['duration'], $jobName . ':total_ms', $durationMs);
        $this->recordMax($keys['duration'], $jobName . ':max_ms', $durationMs);

        if ($durationMs >= $this->config->slowJobMs()) {
            Redis::zIncrBy($keys['slow'], 1, $jobName);
        }

        if ($payloadBytes >= $this->config->largePayloadBytes()) {
            Redis::zIncrBy($keys['large_payload'], 1, $jobName);
        }

        if (in_array($status, ['failed', 'exception'], true)) {
            Redis::zIncrBy($keys['failures'], 1, $jobName);
        }

        Redis::setex($keys['last_event'], $this->config->retentionSeconds(), json_encode([
            'status' => $status,
            'job' => $jobName,
            'queue' => $queue,
            'uuid' => $meta['uuid'] ?? null,
            'payload_bytes' => $payloadBytes,
            'duration_ms' => $durationMs,
            'attempts' => $meta['attempts'] ?? null,
            'diagnostic_context' => $meta['diagnostic_context'] ?? [],
            'exception' => $exception,
            'finished_at' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        foreach ($keys as $key) {
            Redis::expire($key, $this->config->retentionSeconds());
        }
    }

    private function pullStartMeta(string $uuid): array
    {
        if ($uuid === '') {
            return [];
        }

        $key = $this->config->startKey($uuid);
        $raw = Redis::get($key);
        Redis::del($key);

        return $this->decodeJson((string)$raw);
    }

    private function durationMs(array $startMeta, int $nowMs): int
    {
        $startedAtMs = (int)($startMeta['started_at_ms'] ?? 0);
        if ($startedAtMs <= 0) {
            return 0;
        }

        return max(0, $nowMs - $startedAtMs);
    }

    private function recordMax(string $key, string $field, int $value): void
    {
        $current = (int)Redis::hget($key, $field);
        if ($value > $current) {
            Redis::hset($key, $field, $value);
        }
    }

    private function writeHash(string $key, array $values): void
    {
        foreach ($values as $field => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            Redis::hset($key, (string)$field, (string)$value);
        }
    }

    private function sourceLabel($context): string
    {
        return $this->contextLabel($context, [
            'source',
            'job_stage',
            'location_solution',
            'location_mode',
            'subject_id',
            'gateway_mac',
            'locator_id',
            'target_slug',
        ]);
    }

    private function sourceGroupLabel($context): string
    {
        return $this->contextLabel($context, [
            'source',
            'job_stage',
            'location_solution',
            'location_mode',
            'subject_id',
            'gateway_mac',
        ]);
    }

    private function contextLabel($context, array $fields): string
    {
        if (!is_array($context) || empty($context)) {
            return '';
        }

        $parts = [];
        foreach ($fields as $field) {
            if (isset($context[$field]) && $context[$field] !== '') {
                $parts[] = $field . '=' . $context[$field];
            }
        }

        return mb_substr(implode('|', $parts), 0, 240);
    }

    private function zsetTop(string $key, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $rows = Redis::zRevRange($key, 0, $limit - 1, true) ?: [];
        $result = [];

        foreach ($rows as $name => $score) {
            $result[] = [
                'name' => (string)$name,
                'score' => (float)$score,
            ];
        }

        return $result;
    }

    private function decodeJson(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function startTtlSeconds(): int
    {
        return max($this->config->retentionSeconds(), (int)config('queue.connections.redis.retry_after', 30) + 3900);
    }

    private function nowMs(): int
    {
        return (int)floor(microtime(true) * 1000);
    }
}
