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
 * 从 Scheduler 持久化的单个 Redis Key 读取 PostgreSQL AppStats 快照。
 *
 * 该类位于每五秒可能调用一次的 HTTP 路径，禁止直接连接 PostgreSQL 或触发采样。
 */
final class PostgresDatabaseAppStatsSection implements AppStatsSectionProvider
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
        return 'postgres_database';
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        if (! (bool) $this->config->get('database.postgres_monitor.enabled', false)) {
            return $this->result('disabled', null, [], 'postgres_monitor_disabled');
        }

        try {
            $connectionName = $this->nullableString(
                $this->config->get('database.postgres_monitor.state_redis_connection')
            );
            $stateKey = (string) $this->config->get(
                'database.postgres_monitor.state_key',
                'easy-tool:postgres-monitor:state'
            );
            $raw = $this->redis->connection($connectionName)->get($stateKey);

            if (! is_string($raw) || $raw === '') {
                return $this->result(
                    'waiting',
                    null,
                    [],
                    'postgres_monitor_waiting_for_first_sample'
                );
            }

            $state = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (($state['schema_version'] ?? null) !== 1
                || ! is_array($state['snapshot'] ?? null)) {
                return $this->result(
                    'waiting',
                    null,
                    [],
                    'postgres_monitor_waiting_for_complete_sample'
                );
            }

            $sampledAt = max(0, (int) ($state['last_sampled_at'] ?? 0));
            $level = in_array($state['level'] ?? null, ['normal', 'warning', 'error'], true)
                ? (string) $state['level']
                : 'error';

            if ($level === 'error' && ($state['last_failure_at'] ?? null) !== null) {
                return $this->result(
                    'error',
                    $sampledAt > 0 ? date(DATE_ATOM, $sampledAt) : null,
                    $this->whitelistMetrics($state['snapshot']),
                    'postgres_monitor_sampling_failed'
                );
            }

            if ($sampledAt === 0) {
                return $this->result(
                    'waiting',
                    null,
                    [],
                    'postgres_monitor_waiting_for_complete_sample'
                );
            }

            $staleSeconds = max(
                1,
                (int) $this->config->get('app_stats.postgres_database_stale_seconds', 120)
            );
            $delayed = (($this->clock)() - $sampledAt) > $staleSeconds;

            return $this->result(
                $delayed ? 'delayed' : $level,
                date(DATE_ATOM, $sampledAt),
                $this->whitelistMetrics($state['snapshot']),
                $delayed ? 'postgres_monitor_sample_delayed' : null
            );
        } catch (Throwable) {
            return $this->result(
                'error',
                null,
                [],
                'postgres_monitor_state_read_failed'
            );
        }
    }

    /** @return array<string, int|float|string|bool|null> */
    private function whitelistMetrics(array $snapshot): array
    {
        $locators = is_array($snapshot['locators'] ?? null) ? $snapshot['locators'] : [];
        $integer = static fn (array $source, string $key): ?int => isset($source[$key])
            && is_numeric($source[$key])
            ? max(0, (int) $source[$key])
            : null;
        $decimal = static fn (array $source, string $key): ?float => isset($source[$key])
            && is_numeric($source[$key])
            ? max(0.0, (float) $source[$key])
            : null;

        return [
            'database' => is_string($snapshot['database'] ?? null)
                ? $snapshot['database']
                : null,
            'max_connections' => $integer($snapshot, 'max_connections'),
            'normal_connection_capacity' => $integer($snapshot, 'normal_connection_capacity'),
            'total_connections' => $integer($snapshot, 'total_connections'),
            'normal_connection_headroom' => $integer($snapshot, 'normal_connection_headroom'),
            'connection_used_percent' => $decimal($snapshot, 'connection_used_percent'),
            'current_database_connections' => $integer(
                $snapshot,
                'current_database_connections'
            ),
            'active_connections' => $integer($snapshot, 'active_connections'),
            'idle_connections' => $integer($snapshot, 'idle_connections'),
            'idle_in_transaction_connections' => $integer(
                $snapshot,
                'idle_in_transaction_connections'
            ),
            'lock_waiting_connections' => $integer($snapshot, 'lock_waiting_connections'),
            'blocked_connections' => $integer($snapshot, 'blocked_connections'),
            'longest_transaction_seconds' => $integer(
                $snapshot,
                'longest_transaction_seconds'
            ),
            'deadlocks_delta' => $integer($snapshot, 'deadlocks_delta'),
            'cache_hit_percent' => $decimal($snapshot, 'cache_hit_percent'),
            'sample_duration_ms' => $decimal($snapshot, 'sample_duration_ms'),
            'locators_sampled_at' => $integer($snapshot, 'locators_sampled_at'),
            'locators_live_tuples' => $integer($locators, 'live_tuples'),
            'locators_dead_tuples' => $integer($locators, 'dead_tuples'),
            'locators_updates_total' => $integer($locators, 'updates_total'),
            'locators_hot_update_percent' => $decimal($locators, 'hot_update_percent'),
            'locators_autovacuum_count' => $integer($locators, 'autovacuum_count'),
            'locators_last_autovacuum' => is_string($locators['last_autovacuum'] ?? null)
                ? $locators['last_autovacuum']
                : null,
            'locators_total_bytes' => $integer($locators, 'total_bytes'),
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
            'title' => 'PostgreSQL 运行监控',
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
