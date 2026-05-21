<?php

namespace Mallto\Tool\Domain\QueueDiagnostic;

use Carbon\Carbon;
use Mallto\Tool\Utils\ConfigUtils;

class QueueDiagnosticConfig
{
    public const KEY_ENABLED = 'queue_diagnostic_monitor_enabled';
    public const KEY_WINDOW_SECONDS = 'queue_diagnostic_window_seconds';
    public const KEY_RETENTION_SECONDS = 'queue_diagnostic_retention_seconds';
    public const KEY_SNAPSHOT_ENABLED = 'queue_diagnostic_snapshot_enabled';
    public const KEY_DB_SNAPSHOT_ENABLED = 'queue_diagnostic_db_snapshot_enabled';
    public const KEY_LOG_ANOMALY_ENABLED = 'queue_diagnostic_log_anomaly_enabled';
    public const KEY_QUEUES = 'queue_diagnostic_queues';
    public const KEY_TOP_LIMIT = 'queue_diagnostic_top_limit';
    public const KEY_MEMORY_WARNING_BYTES = 'queue_diagnostic_memory_warning_bytes';
    public const KEY_MEMORY_ERROR_BYTES = 'queue_diagnostic_memory_error_bytes';
    public const KEY_QUEUE_WARNING_SIZE = 'queue_diagnostic_queue_warning_size';
    public const KEY_QUEUE_ERROR_SIZE = 'queue_diagnostic_queue_error_size';
    public const KEY_SLOW_JOB_MS = 'queue_diagnostic_slow_job_ms';
    public const KEY_LARGE_PAYLOAD_BYTES = 'queue_diagnostic_large_payload_bytes';
    public const KEY_SCAN_ENABLED = 'queue_diagnostic_scan_enabled';
    public const KEY_SCAN_COUNT = 'queue_diagnostic_scan_count';

    /**
     * Keep config cache short so direct DB edits self-heal, while admin saves
     * still take effect immediately via ClearCacheUsecase.
     */
    private const CONFIG_CACHE_SECONDS = 5;

    public function enabled(): bool
    {
        return $this->boolValue(self::KEY_ENABLED, false);
    }

    public function snapshotEnabled(): bool
    {
        return $this->boolValue(self::KEY_SNAPSHOT_ENABLED, true);
    }

    public function dbSnapshotEnabled(): bool
    {
        return $this->boolValue(self::KEY_DB_SNAPSHOT_ENABLED, false);
    }

    public function logAnomalyEnabled(): bool
    {
        return $this->boolValue(self::KEY_LOG_ANOMALY_ENABLED, true);
    }

    public function scanEnabled(): bool
    {
        return $this->boolValue(self::KEY_SCAN_ENABLED, false);
    }

    public function windowSeconds(): int
    {
        return max(10, $this->intValue(self::KEY_WINDOW_SECONDS, 60));
    }

    public function retentionSeconds(): int
    {
        return max(300, $this->intValue(self::KEY_RETENTION_SECONDS, 86400));
    }

    public function topLimit(): int
    {
        return max(1, min(100, $this->intValue(self::KEY_TOP_LIMIT, 20)));
    }

    public function memoryWarningBytes(): int
    {
        return max(0, $this->intValue(self::KEY_MEMORY_WARNING_BYTES, 805306368));
    }

    public function memoryErrorBytes(): int
    {
        return max(0, $this->intValue(self::KEY_MEMORY_ERROR_BYTES, 943718400));
    }

    public function queueWarningSize(): int
    {
        return max(0, $this->intValue(self::KEY_QUEUE_WARNING_SIZE, 1000));
    }

    public function queueErrorSize(): int
    {
        return max(0, $this->intValue(self::KEY_QUEUE_ERROR_SIZE, 5000));
    }

    public function slowJobMs(): int
    {
        return max(1, $this->intValue(self::KEY_SLOW_JOB_MS, 3000));
    }

    public function largePayloadBytes(): int
    {
        return max(1, $this->intValue(self::KEY_LARGE_PAYLOAD_BYTES, 65536));
    }

    public function scanCount(): int
    {
        return max(10, min(10000, $this->intValue(self::KEY_SCAN_COUNT, 500)));
    }

    public function queues(): array
    {
        $value = $this->rawValue(self::KEY_QUEUES, 'high,pgsql-write,location-result-storage,mid,default');

        if (is_string($value) && str_starts_with(trim($value), '[')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $this->normalizeList($decoded);
            }
        }

        if (is_array($value)) {
            return $this->normalizeList($value);
        }

        return $this->normalizeList(explode(',', (string)$value));
    }

    public function redisPrefix(): string
    {
        $unique = config('app.unique') ?: config('app.name', 'app');
        $env = config('app.env', 'local');

        return 'queue_diag:' . $this->sanitizeKeyPart((string)$unique) . ':' . $this->sanitizeKeyPart((string)$env) . ':';
    }

    public function startKey(string $uuid): string
    {
        return $this->redisPrefix() . 'job:' . $this->sanitizeKeyPart($uuid) . ':start';
    }

    public function windowStart(?int $timestamp = null): int
    {
        $timestamp = $timestamp ?: time();
        $windowSeconds = $this->windowSeconds();

        return $timestamp - ($timestamp % $windowSeconds);
    }

    public function windowKey(int $windowStart, string $suffix): string
    {
        return $this->redisPrefix() . 'window:' . $windowStart . ':' . $suffix;
    }

    public function allConfigKeys(): array
    {
        return [
            self::KEY_ENABLED,
            self::KEY_WINDOW_SECONDS,
            self::KEY_RETENTION_SECONDS,
            self::KEY_SNAPSHOT_ENABLED,
            self::KEY_DB_SNAPSHOT_ENABLED,
            self::KEY_LOG_ANOMALY_ENABLED,
            self::KEY_QUEUES,
            self::KEY_TOP_LIMIT,
            self::KEY_MEMORY_WARNING_BYTES,
            self::KEY_MEMORY_ERROR_BYTES,
            self::KEY_QUEUE_WARNING_SIZE,
            self::KEY_QUEUE_ERROR_SIZE,
            self::KEY_SLOW_JOB_MS,
            self::KEY_LARGE_PAYLOAD_BYTES,
            self::KEY_SCAN_ENABLED,
            self::KEY_SCAN_COUNT,
        ];
    }

    public function snapshot(): array
    {
        return [
            'enabled' => $this->enabled(),
            'window_seconds' => $this->windowSeconds(),
            'retention_seconds' => $this->retentionSeconds(),
            'snapshot_enabled' => $this->snapshotEnabled(),
            'db_snapshot_enabled' => $this->dbSnapshotEnabled(),
            'log_anomaly_enabled' => $this->logAnomalyEnabled(),
            'queues' => $this->queues(),
            'top_limit' => $this->topLimit(),
            'memory_warning_bytes' => $this->memoryWarningBytes(),
            'memory_error_bytes' => $this->memoryErrorBytes(),
            'queue_warning_size' => $this->queueWarningSize(),
            'queue_error_size' => $this->queueErrorSize(),
            'slow_job_ms' => $this->slowJobMs(),
            'large_payload_bytes' => $this->largePayloadBytes(),
            'scan_enabled' => $this->scanEnabled(),
            'scan_count' => $this->scanCount(),
            'redis_prefix' => $this->redisPrefix(),
        ];
    }

    private function boolValue(string $key, bool $default): bool
    {
        return filter_var($this->rawValue($key, $default ? '1' : '0'), FILTER_VALIDATE_BOOLEAN);
    }

    private function intValue(string $key, int $default): int
    {
        return (int)$this->rawValue($key, $default);
    }

    private function rawValue(string $key, $default)
    {
        return ConfigUtils::get($key, $default, true, Carbon::now()->addSeconds(self::CONFIG_CACHE_SECONDS));
    }

    private function normalizeList(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function sanitizeKeyPart(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9_.-]/', '_', $value) ?: 'unknown';

        return trim($value, '_') ?: 'unknown';
    }
}
