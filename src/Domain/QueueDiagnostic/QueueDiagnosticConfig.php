<?php

namespace Mallto\Tool\Domain\QueueDiagnostic;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Mallto\Tool\Data\QueueDiagnosticSetting;

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
    public const KEY_BACKLOG_SAMPLE_ENABLED = 'queue_diagnostic_backlog_sample_enabled';
    public const KEY_BACKLOG_SAMPLE_COUNT = 'queue_diagnostic_backlog_sample_count';
    public const KEY_SCAN_ENABLED = 'queue_diagnostic_scan_enabled';
    public const KEY_SCAN_COUNT = 'queue_diagnostic_scan_count';

    /**
     * Keep config cache short so direct DB edits self-heal, while status page
     * saves still take effect immediately via clearSettingsCache().
     */
    private const CONFIG_CACHE_SECONDS = 5;
    private const SETTINGS_CACHE_KEY = 'queue_diagnostic_settings';

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

    public function backlogSampleEnabled(): bool
    {
        return $this->boolValue(self::KEY_BACKLOG_SAMPLE_ENABLED, true);
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

    public function backlogSampleCount(): int
    {
        return max(10, min(1000, $this->intValue(self::KEY_BACKLOG_SAMPLE_COUNT, 200)));
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
        return array_keys($this->settingDefinitions());
    }

    public function settingDefinitions(): array
    {
        return [
            self::KEY_ENABLED => [
                'label' => '总开关',
                'type' => 'boolean',
                'default' => '0',
                'remark' => '开启后记录队列事件和诊断窗口聚合；默认关闭。',
            ],
            self::KEY_WINDOW_SECONDS => [
                'label' => '聚合窗口秒数',
                'type' => 'integer',
                'default' => '60',
                'min' => 10,
                'remark' => '窗口越小越实时，Redis key 数量也越多。',
            ],
            self::KEY_RETENTION_SECONDS => [
                'label' => 'Redis 诊断 key TTL',
                'type' => 'integer',
                'default' => '86400',
                'min' => 300,
                'remark' => '高频窗口数据保留时长，默认 24 小时。',
            ],
            self::KEY_SNAPSHOT_ENABLED => [
                'label' => 'Redis / backlog 快照',
                'type' => 'boolean',
                'default' => '1',
                'remark' => '开启后定时采样 Redis memory、keyspace 和队列 backlog。',
            ],
            self::KEY_DB_SNAPSHOT_ENABLED => [
                'label' => 'PostgreSQL 摘要',
                'type' => 'boolean',
                'default' => '0',
                'remark' => '用于隔天复盘，默认关闭，避免低价值写库。',
            ],
            self::KEY_LOG_ANOMALY_ENABLED => [
                'label' => '异常日志',
                'type' => 'boolean',
                'default' => '1',
                'remark' => '达到 Redis 内存或队列 backlog 阈值时写结构化 log。',
            ],
            self::KEY_QUEUES => [
                'label' => '采样队列',
                'type' => 'csv',
                'default' => 'high,pgsql-write,location-result-storage,mid,default',
                'remark' => '逗号分隔，用于 snapshot 采样 backlog。',
            ],
            self::KEY_TOP_LIMIT => [
                'label' => '榜单条数',
                'type' => 'integer',
                'default' => '20',
                'min' => 1,
                'max' => 100,
                'remark' => '状态页每个榜单最多展示的条数。',
            ],
            self::KEY_MEMORY_WARNING_BYTES => [
                'label' => 'Redis 内存 warning 阈值',
                'type' => 'integer',
                'default' => '805306368',
                'min' => 0,
                'remark' => '默认 768MB。',
            ],
            self::KEY_MEMORY_ERROR_BYTES => [
                'label' => 'Redis 内存 error 阈值',
                'type' => 'integer',
                'default' => '943718400',
                'min' => 0,
                'remark' => '默认 900MB。',
            ],
            self::KEY_QUEUE_WARNING_SIZE => [
                'label' => '单队列 backlog warning 阈值',
                'type' => 'integer',
                'default' => '1000',
                'min' => 0,
                'remark' => '任一采样队列达到该值时标记 warning。',
            ],
            self::KEY_QUEUE_ERROR_SIZE => [
                'label' => '单队列 backlog error 阈值',
                'type' => 'integer',
                'default' => '5000',
                'min' => 0,
                'remark' => '任一采样队列达到该值时标记 error。',
            ],
            self::KEY_SLOW_JOB_MS => [
                'label' => '慢任务阈值 ms',
                'type' => 'integer',
                'default' => '3000',
                'min' => 1,
                'remark' => 'Job 耗时超过该值会进入慢任务榜单。',
            ],
            self::KEY_LARGE_PAYLOAD_BYTES => [
                'label' => '大 payload 阈值 bytes',
                'type' => 'integer',
                'default' => '65536',
                'min' => 1,
                'remark' => '默认 64KB。',
            ],
            self::KEY_BACKLOG_SAMPLE_ENABLED => [
                'label' => 'Backlog 样本',
                'type' => 'boolean',
                'default' => '1',
                'remark' => '开启后 snapshot 抽样待消费队列 payload，统计 pending Job 构成；不保存 raw payload。',
            ],
            self::KEY_BACKLOG_SAMPLE_COUNT => [
                'label' => 'Backlog 单队列样本数',
                'type' => 'integer',
                'default' => '200',
                'min' => 10,
                'max' => 1000,
                'remark' => '每个队列最多抽样的 pending payload 数量；队列很大时不会全量遍历。',
            ],
            self::KEY_SCAN_ENABLED => [
                'label' => '低频 SCAN',
                'type' => 'boolean',
                'default' => '0',
                'remark' => '预留开关，默认关闭；高峰期不要开启全库扫描。',
            ],
            self::KEY_SCAN_COUNT => [
                'label' => 'SCAN 单次数量',
                'type' => 'integer',
                'default' => '500',
                'min' => 10,
                'max' => 10000,
                'remark' => '预留参数，限制单次 SCAN 数量。',
            ],
        ];
    }

    public function currentSettings(): array
    {
        $settings = $this->settings();
        $rows = [];

        foreach ($this->settingDefinitions() as $key => $definition) {
            $value = array_key_exists($key, $settings)
                ? $settings[$key]
                : $definition['default'];

            $rows[$key] = array_merge($definition, [
                'key' => $key,
                'value' => (string)$value,
                'is_default' => !array_key_exists($key, $settings),
            ]);
        }

        return $rows;
    }

    public function saveSettings(array $input): void
    {
        foreach ($this->settingDefinitions() as $key => $definition) {
            $value = $this->normalizeSettingForSave($key, $input[$key] ?? null, $definition);
            $default = (string)$definition['default'];

            if ($value === $default) {
                QueueDiagnosticSetting::query()->where('key', $key)->delete();
                continue;
            }

            QueueDiagnosticSetting::query()->updateOrCreate([
                'key' => $key,
            ], [
                'value' => $value,
                'remark' => $definition['remark'] ?? $definition['label'] ?? null,
            ]);
        }

        $this->clearSettingsCache();
    }

    public function clearSettingsCache(): void
    {
        Cache::store('local_redis')->forget(self::SETTINGS_CACHE_KEY);
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
            'backlog_sample_enabled' => $this->backlogSampleEnabled(),
            'backlog_sample_count' => $this->backlogSampleCount(),
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
        $settings = $this->settings();

        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    private function settings(): array
    {
        $settings = Cache::store('local_redis')->get(self::SETTINGS_CACHE_KEY);
        if (is_array($settings)) {
            return $settings;
        }

        $settings = $this->loadSettings();
        Cache::store('local_redis')->put(
            self::SETTINGS_CACHE_KEY,
            $settings,
            Carbon::now()->addSeconds(self::CONFIG_CACHE_SECONDS)
        );

        return $settings;
    }

    private function loadSettings(): array
    {
        try {
            return QueueDiagnosticSetting::query()
                ->pluck('value', 'key')
                ->all();
        } catch (\Throwable $throwable) {
            return [];
        }
    }

    private function normalizeSettingForSave(string $key, $value, array $definition): string
    {
        $type = $definition['type'] ?? 'string';

        if ($type === 'boolean') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        if ($type === 'integer') {
            $value = is_numeric($value) ? (int)$value : (int)$definition['default'];

            if (array_key_exists('min', $definition)) {
                $value = max((int)$definition['min'], $value);
            }

            if (array_key_exists('max', $definition)) {
                $value = min((int)$definition['max'], $value);
            }

            return (string)$value;
        }

        if ($type === 'csv') {
            $items = is_array($value)
                ? $value
                : explode(',', (string)$value);
            $items = $this->normalizeList($items);

            return empty($items) ? (string)$definition['default'] : implode(',', $items);
        }

        return trim((string)$value);
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
