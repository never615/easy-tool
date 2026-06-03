<?php

namespace Mallto\Tool\Domain\QueueDiagnostic;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Mallto\Tool\Data\QueueDiagnosticSnapshot;

class QueueDiagnosticSnapshotter
{
    public function __construct(
        private readonly QueueDiagnosticConfig $config,
        private readonly QueueDiagnosticRedisStore $store
    ) {
    }

    public function capture(?int $windowStart = null): array
    {
        $windowStart = $windowStart ?: $this->config->windowStart();
        $redisMemory = $this->redisInfo('memory');
        $keyspace = $this->redisInfo('keyspace');
        $queueSizes = $this->queueSizes();
        $anomaly = $this->detectAnomaly($redisMemory, $queueSizes);

        $this->store->recordResourceSnapshot($windowStart, $redisMemory, $keyspace, $queueSizes, $anomaly);

        $snapshot = array_merge($this->store->windowSnapshot($windowStart), [
            'redis_memory' => $redisMemory,
            'keyspace' => $keyspace,
            'queue_sizes' => $queueSizes,
            'anomaly' => $anomaly,
            'config' => $this->config->snapshot(),
        ]);

        $this->logAnomaly($snapshot);
        $this->persistSnapshot($snapshot);

        return $snapshot;
    }

    private function redisInfo(string $section): array
    {
        try {
            $client = Redis::connection()->client();
            $info = method_exists($client, 'info')
                ? $client->info($section)
                : Redis::command('INFO', [ $section ]);

            return is_array($info) ? $this->flattenInfo($info) : [];
        } catch (\Throwable $throwable) {
            return [
                'error' => $throwable->getMessage(),
            ];
        }
    }

    private function queueSizes(): array
    {
        $sizes = [];

        foreach ($this->config->queues() as $queue) {
            try {
                $sizes[$queue] = Queue::connection('redis')->size($queue);
            } catch (\Throwable $throwable) {
                $sizes[$queue] = 'error: ' . $throwable->getMessage();
            }
        }

        return $sizes;
    }

    private function detectAnomaly(array $redisMemory, array $queueSizes): array
    {
        $level = null;
        $reasons = [];
        $usedMemory = (int)($redisMemory['used_memory'] ?? 0);

        if ($usedMemory > 0 && $usedMemory >= $this->config->memoryErrorBytes()) {
            $level = 'error';
            $reasons[] = 'redis_used_memory_error';
        } elseif ($usedMemory > 0 && $usedMemory >= $this->config->memoryWarningBytes()) {
            $level = 'warning';
            $reasons[] = 'redis_used_memory_warning';
        }

        foreach ($queueSizes as $queue => $size) {
            if (!is_numeric($size)) {
                continue;
            }

            $size = (int)$size;
            if ($size >= $this->config->queueErrorSize()) {
                $level = 'error';
                $reasons[] = 'queue_backlog_error:' . $queue;
            } elseif ($size >= $this->config->queueWarningSize() && $level !== 'error') {
                $level = 'warning';
                $reasons[] = 'queue_backlog_warning:' . $queue;
            }
        }

        return [
            'level' => $level,
            'reasons' => array_values(array_unique($reasons)),
            'checked_at' => time(),
        ];
    }

    private function logAnomaly(array $snapshot): void
    {
        $level = $snapshot['anomaly']['level'] ?? null;
        if (!$level || !$this->config->logAnomalyEnabled()) {
            return;
        }

        $dedupeKey = $this->config->redisPrefix() . 'last_anomaly_log:' . md5(json_encode($snapshot['anomaly']['reasons'] ?? []));
        if (Redis::get($dedupeKey)) {
            return;
        }

        Redis::setex($dedupeKey, 300, 1);

        $context = [
            'window_started_at' => $snapshot['window_started_at'] ?? null,
            'redis_memory' => $snapshot['redis_memory'] ?? [],
            'queue_sizes' => $snapshot['queue_sizes'] ?? [],
            'top_jobs' => $snapshot['jobs'] ?? [],
            'slow_jobs' => $snapshot['slow_jobs'] ?? [],
            'large_payload_jobs' => $snapshot['large_payload_jobs'] ?? [],
            'failed_jobs' => $snapshot['failed_jobs'] ?? [],
            'anomaly' => $snapshot['anomaly'] ?? [],
        ];

        if ($level === 'error') {
            Log::error('Queue diagnostic anomaly detected', $context);

            return;
        }

        Log::warning('Queue diagnostic anomaly detected', $context);
    }

    private function persistSnapshot(array $snapshot): void
    {
        if (!$this->config->dbSnapshotEnabled()) {
            return;
        }

        try {
            $windowStartedAt = Carbon::createFromTimestamp((int)$snapshot['window_started_at']);
            $redisMemory = $snapshot['redis_memory'] ?? [];
            $anomaly = $snapshot['anomaly'] ?? [];

            QueueDiagnosticSnapshot::query()->updateOrCreate([
                'env' => (string)config('app.env', 'local'),
                'app_unique' => (string)(config('app.unique') ?: config('app.name', 'app')),
                'window_started_at' => $windowStartedAt,
            ], [
                'window_seconds' => $this->config->windowSeconds(),
                'redis_used_memory' => (int)($redisMemory['used_memory'] ?? 0),
                'redis_used_memory_peak' => (int)($redisMemory['used_memory_peak'] ?? 0),
                'redis_mem_fragmentation_ratio' => isset($redisMemory['mem_fragmentation_ratio'])
                    ? (string)$redisMemory['mem_fragmentation_ratio']
                    : null,
                'queue_sizes' => $snapshot['queue_sizes'] ?? [],
                'top_jobs' => $snapshot['jobs'] ?? [],
                'sources' => $snapshot['sources'] ?? [],
                'source_groups' => $snapshot['source_groups'] ?? [],
                'slow_jobs' => $snapshot['slow_jobs'] ?? [],
                'large_payload_jobs' => $snapshot['large_payload_jobs'] ?? [],
                'failed_jobs' => $snapshot['failed_jobs'] ?? [],
                'keyspace' => $snapshot['keyspace'] ?? [],
                'scan_patterns' => null,
                'anomaly_level' => $anomaly['level'] ?? null,
                'anomaly_reasons' => $anomaly['reasons'] ?? [],
            ]);
        } catch (\Throwable $throwable) {
            Log::warning('Queue diagnostic snapshot persist failed', [
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function flattenInfo(array $info): array
    {
        $flattened = [];

        foreach ($info as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $nestedKey => $nestedValue) {
                    $flattened[$nestedKey] = $nestedValue;
                }
                continue;
            }

            $flattened[$key] = $value;
        }

        return $flattened;
    }
}
