<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\AppStats\Sections;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Mallto\Tool\Domain\AppStats\Contracts\AppStatsSectionProvider;
use Throwable;

/**
 * 从 Scheduler 持久化的单个 Redis Key 读取 MongoDB AppStats 快照。
 *
 * HTTP 路径禁止直接连接 MongoDB 或触发采样。
 */
final class MongoDatabaseAppStatsSection implements AppStatsSectionProvider
{
    /** @var callable(): int */
    private $clock;

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly ConfigRepository $config,
        ?callable $clock = null
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function key(): string
    {
        return 'mongodb_database';
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        if (! (bool) $this->config->get('database.mongodb_monitor.enabled', false)) {
            return $this->result('disabled', null, [], 'mongodb_monitor_disabled');
        }

        try {
            $connection = $this->nullableString(
                $this->config->get('database.mongodb_monitor.state_redis_connection')
            );
            $key = (string) $this->config->get(
                'database.mongodb_monitor.state_key',
                'easy-tool:mongodb-monitor:state'
            );
            $raw = $this->redis->connection($connection)->get($key);

            if (! is_string($raw) || $raw === '') {
                return $this->result(
                    'waiting',
                    null,
                    [],
                    'mongodb_monitor_waiting_for_first_sample'
                );
            }

            $state = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (($state['schema_version'] ?? null) !== 1
                || ! is_array($state['snapshot'] ?? null)) {
                return $this->result(
                    'waiting',
                    null,
                    [],
                    'mongodb_monitor_waiting_for_complete_sample'
                );
            }

            $sampledAt = max(0, (int) ($state['last_sampled_at'] ?? 0));
            $metrics = $this->whitelistMetrics($state['snapshot']);
            if (($state['last_failure_at'] ?? null) !== null) {
                return $this->result(
                    'error',
                    $sampledAt > 0 ? date(DATE_ATOM, $sampledAt) : null,
                    $metrics,
                    'mongodb_monitor_sampling_failed'
                );
            }
            if ($sampledAt === 0) {
                return $this->result(
                    'waiting',
                    null,
                    [],
                    'mongodb_monitor_waiting_for_complete_sample'
                );
            }

            $level = in_array($state['level'] ?? null, ['normal', 'warning', 'error'], true)
                ? (string) $state['level']
                : 'error';
            $staleSeconds = max(
                1,
                (int) $this->config->get('app_stats.mongodb_database_stale_seconds', 120)
            );
            $delayed = (($this->clock)() - $sampledAt) > $staleSeconds;
            $limited = ($metrics['monitoring_mode'] ?? null) === 'availability_only';

            return $this->result(
                $delayed ? 'delayed' : $level,
                date(DATE_ATOM, $sampledAt),
                $metrics,
                $delayed
                    ? 'mongodb_monitor_sample_delayed'
                    : ($limited ? 'mongodb_monitor_limited_metrics' : null)
            );
        } catch (Throwable) {
            return $this->result('error', null, [], 'mongodb_monitor_state_read_failed');
        }
    }

    /** @return array<string, int|float|string|bool|null> */
    private function whitelistMetrics(array $snapshot): array
    {
        $integer = static fn (string $key): ?int => isset($snapshot[$key])
            && is_numeric($snapshot[$key])
            ? max(0, (int) $snapshot[$key])
            : null;
        $decimal = static fn (string $key): ?float => isset($snapshot[$key])
            && is_numeric($snapshot[$key])
            ? max(0.0, (float) $snapshot[$key])
            : null;

        return [
            'monitoring_mode' => in_array(
                $snapshot['monitoring_mode'] ?? null,
                ['server_status', 'availability_only'],
                true
            ) ? $snapshot['monitoring_mode'] : null,
            'version' => is_string($snapshot['version'] ?? null) ? $snapshot['version'] : null,
            'process' => is_string($snapshot['process'] ?? null) ? $snapshot['process'] : null,
            'uptime_seconds' => $integer('uptime_seconds'),
            'is_writable_primary' => (bool) ($snapshot['is_writable_primary'] ?? false),
            'secondary' => (bool) ($snapshot['secondary'] ?? false),
            'replica_set_name' => is_string($snapshot['replica_set_name'] ?? null)
                ? $snapshot['replica_set_name']
                : null,
            'current_connections' => $integer('current_connections'),
            'available_connections' => $integer('available_connections'),
            'connection_capacity' => $integer('connection_capacity'),
            'connection_used_percent' => $decimal('connection_used_percent'),
            'active_connections' => $integer('active_connections'),
            'rejected_connections' => $integer('rejected_connections'),
            'rejected_connections_delta' => $integer('rejected_connections_delta'),
            'resident_memory_bytes' => $integer('resident_memory_bytes'),
            'virtual_memory_bytes' => $integer('virtual_memory_bytes'),
            'wiredtiger_cache_bytes' => $integer('wiredtiger_cache_bytes'),
            'wiredtiger_cache_max_bytes' => $integer('wiredtiger_cache_max_bytes'),
            'wiredtiger_cache_used_percent' => $decimal('wiredtiger_cache_used_percent'),
            'wiredtiger_cache_dirty_bytes' => $integer('wiredtiger_cache_dirty_bytes'),
            'sample_duration_ms' => $decimal('sample_duration_ms'),
        ];
    }

    /** @return array<string, mixed> */
    private function result(
        string $status,
        ?string $sampledAt,
        array $metrics,
        ?string $message
    ): array {
        return [
            'title' => 'MongoDB 运行监控',
            'status' => $status,
            'sampled_at' => $sampledAt,
            'metrics' => $metrics,
            'message' => $message,
        ];
    }

    private function nullableString($value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
