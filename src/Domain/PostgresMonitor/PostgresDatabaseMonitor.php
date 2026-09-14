<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\PostgresMonitor;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * PostgreSQL 的低开销运行状态采样器。
 *
 * 每分钟只读取共享内存统计视图；locators 表健康与关系大小最多每五分钟采样一次。
 * 禁止扫描业务表、执行 COUNT(*)、VACUUM、ANALYZE 或重置统计。采样结果写入一个
 * 共享 Redis 状态 Key，AppStats HTTP 请求只消费该快照，不直接连接 PostgreSQL。
 */
final class PostgresDatabaseMonitor
{
    private readonly bool $enabled;

    private readonly ?string $databaseConnection;

    private readonly ?string $redisConnection;

    private readonly string $stateKey;

    private readonly int $stateTtlSeconds;

    private readonly int $tableSampleIntervalSeconds;

    private readonly int $statementTimeoutMs;

    private readonly int $lockTimeoutMs;

    private readonly float $warningPercent;

    private readonly float $errorPercent;

    private readonly int $longTransactionWarningSeconds;

    private readonly int $slowSampleWarningMs;

    /** @var callable(): int */
    private $clock;

    public function __construct(
        private readonly ConnectionResolverInterface $databases,
        private readonly RedisFactory $redis,
        private readonly LoggerInterface $logger,
        array $config,
        ?callable $clock = null
    ) {
        $this->enabled = (bool) ($config['enabled'] ?? false);
        $this->databaseConnection = $this->nullableString($config['connection'] ?? null);
        $this->redisConnection = $this->nullableString($config['state_redis_connection'] ?? null);
        $this->stateKey = (string) ($config['state_key'] ?? 'easy-tool:postgres-monitor:state');
        $this->stateTtlSeconds = max(300, (int) ($config['state_ttl_seconds'] ?? 86400));
        $this->tableSampleIntervalSeconds = max(
            60,
            (int) ($config['table_sample_interval_seconds'] ?? 300)
        );
        $this->statementTimeoutMs = min(5000, max(100, (int) ($config['statement_timeout_ms'] ?? 1000)));
        $this->lockTimeoutMs = min(
            $this->statementTimeoutMs,
            max(50, (int) ($config['lock_timeout_ms'] ?? 200))
        );

        $warning = min(100.0, max(1.0, (float) ($config['warning_percent'] ?? 70)));
        $error = min(100.0, max(1.0, (float) ($config['error_percent'] ?? 85)));
        $this->warningPercent = min($warning, $error);
        $this->errorPercent = max($warning, $error);
        $this->longTransactionWarningSeconds = max(
            1,
            (int) ($config['long_transaction_warning_seconds'] ?? 60)
        );
        $this->slowSampleWarningMs = max(1, (int) ($config['slow_sample_warning_ms'] ?? 200));
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * 执行一次采样；所有基础设施异常都收口为结构化结果，避免中断同一分钟的其它调度任务。
     *
     * @return array<string, mixed>
     */
    public function run(): array
    {
        if (! $this->enabled) {
            return [
                'enabled' => false,
                'ok' => true,
                'level' => 'disabled',
            ];
        }

        $now = ($this->clock)();
        $stateConnection = null;
        $previous = [];
        $stage = 'state_connection';

        try {
            $stateConnection = $this->redis->connection($this->redisConnection);
            $stage = 'state_read';
            $previous = $this->decodeState($stateConnection->get($this->stateKey));
            $stage = 'database_connection';
            $database = $this->databases->connection($this->databaseConnection);

            if ($database->getDriverName() !== 'pgsql') {
                return [
                    'enabled' => true,
                    'ok' => false,
                    'level' => 'error',
                    'reason' => 'unsupported_driver',
                ];
            }

            $startedAt = hrtime(true);
            $stage = 'database_sample';
            [$core, $table, $tableSampledAt] = $this->sample(
                $database,
                $previous,
                $now
            );
            $sampleDurationMs = round((hrtime(true) - $startedAt) / 1_000_000, 2);
            $snapshot = $this->buildSnapshot(
                $core,
                $table,
                $tableSampledAt,
                $previous,
                $sampleDurationMs
            );
            [$level, $reasons] = $this->classify($snapshot);
            $state = [
                'schema_version' => 1,
                'level' => $level,
                'reasons' => $reasons,
                'snapshot' => $snapshot,
                'last_sampled_at' => $now,
                'last_attempted_at' => $now,
                'last_failure_at' => null,
            ];

            $stage = 'state_write';
            $stateConnection->setex(
                $this->stateKey,
                $this->stateTtlSeconds,
                json_encode(
                    $state,
                    JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_PRESERVE_ZERO_FRACTION
                )
            );

            return [
                'enabled' => true,
                'ok' => true,
                'level' => $level,
                'reasons' => $reasons,
                'snapshot' => $snapshot,
            ];
        } catch (Throwable $exception) {
            $this->logSamplingFailure($stage, $exception);
            $this->storeFailureState($stateConnection, $previous, $now);

            return [
                'enabled' => true,
                'ok' => false,
                'level' => 'error',
                'reason' => 'sampling_failed',
            ];
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: int}
     */
    private function sample(ConnectionInterface $database, array $previous, int $now): array
    {
        $database->beginTransaction();

        try {
            $database->statement('SET TRANSACTION READ ONLY');
            $database->statement("SET LOCAL statement_timeout = '{$this->statementTimeoutMs}ms'");
            $database->statement("SET LOCAL lock_timeout = '{$this->lockTimeoutMs}ms'");

            $core = $this->firstRow($database->select($this->coreSql()));
            $previousSnapshot = is_array($previous['snapshot'] ?? null)
                ? $previous['snapshot']
                : [];
            $previousTable = is_array($previousSnapshot['locators'] ?? null)
                ? $previousSnapshot['locators']
                : [];
            $tableSampledAt = max(
                0,
                (int) ($previousSnapshot['locators_sampled_at'] ?? 0)
            );
            $table = $previousTable;

            if ($tableSampledAt === 0 || ($now - $tableSampledAt) >= $this->tableSampleIntervalSeconds) {
                $table = $this->firstRow($database->select($this->locatorsSql()));
                $tableSampledAt = $now;
            }

            $database->commit();

            return [$core, $table, $tableSampledAt];
        } catch (Throwable $exception) {
            if ($database->transactionLevel() > 0) {
                $database->rollBack();
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function buildSnapshot(
        array $core,
        array $table,
        int $tableSampledAt,
        array $previous,
        float $sampleDurationMs
    ): array {
        $maxConnections = max(0, (int) ($core['max_connections'] ?? 0));
        $superuserReserved = max(0, (int) ($core['superuser_reserved_connections'] ?? 0));
        $reserved = max(0, (int) ($core['reserved_connections'] ?? 0));
        $totalConnections = max(0, (int) ($core['total_connections'] ?? 0));
        $normalCapacity = max(0, $maxConnections - $superuserReserved - $reserved);
        $normalHeadroom = max(0, $normalCapacity - $totalConnections);
        $usedPercent = $normalCapacity > 0
            ? round(($totalConnections / $normalCapacity) * 100, 2)
            : null;
        $previousSnapshot = is_array($previous['snapshot'] ?? null)
            ? $previous['snapshot']
            : [];
        $deadlocksTotal = max(0, (int) ($core['deadlocks_total'] ?? 0));
        // pg_stat_database.deadlocks 是自统计重置以来的累计值；首次采样没有基线，不能把
        // 历史死锁全部误报为“本分钟新增”。从第二次采样开始才计算增量。
        $deadlocksDelta = array_key_exists('deadlocks_total', $previousSnapshot)
            ? max(0, $deadlocksTotal - max(0, (int) $previousSnapshot['deadlocks_total']))
            : 0;

        return [
            'database' => isset($core['database']) ? (string) $core['database'] : null,
            'max_connections' => $maxConnections,
            'superuser_reserved_connections' => $superuserReserved,
            'reserved_connections' => $reserved,
            'normal_connection_capacity' => $normalCapacity,
            'total_connections' => $totalConnections,
            'normal_connection_headroom' => $normalHeadroom,
            'absolute_connection_headroom' => max(0, $maxConnections - $totalConnections),
            'connection_used_percent' => $usedPercent,
            'current_database_connections' => max(
                0,
                (int) ($core['current_database_connections'] ?? 0)
            ),
            'active_connections' => max(0, (int) ($core['active_connections'] ?? 0)),
            'idle_connections' => max(0, (int) ($core['idle_connections'] ?? 0)),
            'idle_in_transaction_connections' => max(
                0,
                (int) ($core['idle_in_transaction_connections'] ?? 0)
            ),
            'lock_waiting_connections' => max(
                0,
                (int) ($core['lock_waiting_connections'] ?? 0)
            ),
            'blocked_connections' => max(0, (int) ($core['blocked_connections'] ?? 0)),
            'longest_transaction_seconds' => max(
                0,
                (int) ($core['longest_transaction_seconds'] ?? 0)
            ),
            'deadlocks_total' => $deadlocksTotal,
            'deadlocks_delta' => $deadlocksDelta,
            'cache_hit_percent' => isset($core['cache_hit_percent'])
                ? round((float) $core['cache_hit_percent'], 2)
                : null,
            'sample_duration_ms' => $sampleDurationMs,
            'locators_sampled_at' => $tableSampledAt,
            'locators' => $this->normalizeLocatorsSnapshot($table),
        ];
    }

    /** @return array<string, int|float|string|null> */
    private function normalizeLocatorsSnapshot(array $table): array
    {
        $updated = max(0, (int) ($table['n_tup_upd'] ?? 0));
        $hotUpdated = max(0, (int) ($table['n_tup_hot_upd'] ?? 0));

        return [
            'live_tuples' => isset($table['n_live_tup']) ? max(0, (int) $table['n_live_tup']) : null,
            'dead_tuples' => isset($table['n_dead_tup']) ? max(0, (int) $table['n_dead_tup']) : null,
            'updates_total' => $updated,
            'hot_updates_total' => $hotUpdated,
            'hot_update_percent' => $updated > 0
                ? round(($hotUpdated / $updated) * 100, 2)
                : null,
            'autovacuum_count' => isset($table['autovacuum_count'])
                ? max(0, (int) $table['autovacuum_count'])
                : null,
            'autoanalyze_count' => isset($table['autoanalyze_count'])
                ? max(0, (int) $table['autoanalyze_count'])
                : null,
            'last_autovacuum' => isset($table['last_autovacuum'])
                ? (string) $table['last_autovacuum']
                : null,
            'last_autoanalyze' => isset($table['last_autoanalyze'])
                ? (string) $table['last_autoanalyze']
                : null,
            'table_bytes' => isset($table['table_bytes']) ? max(0, (int) $table['table_bytes']) : null,
            'indexes_bytes' => isset($table['indexes_bytes'])
                ? max(0, (int) $table['indexes_bytes'])
                : null,
            'total_bytes' => isset($table['total_bytes']) ? max(0, (int) $table['total_bytes']) : null,
        ];
    }

    /** @return array{0: string, 1: array<int, string>} */
    private function classify(array $snapshot): array
    {
        $level = 'normal';
        $reasons = [];
        $usedPercent = $snapshot['connection_used_percent'];

        if (is_numeric($usedPercent) && (float) $usedPercent >= $this->errorPercent) {
            $level = 'error';
            $reasons[] = 'connection_usage_error';
        } elseif (is_numeric($usedPercent) && (float) $usedPercent >= $this->warningPercent) {
            $level = 'warning';
            $reasons[] = 'connection_usage_warning';
        }

        $warningChecks = [
            'idle_in_transaction_connections' => 'idle_in_transaction_detected',
            'lock_waiting_connections' => 'lock_wait_detected',
            'blocked_connections' => 'blocked_connection_detected',
            'deadlocks_delta' => 'deadlock_detected',
        ];

        foreach ($warningChecks as $metric => $reason) {
            if ((int) ($snapshot[$metric] ?? 0) > 0) {
                $level = $level === 'error' ? 'error' : 'warning';
                $reasons[] = $reason;
            }
        }

        if ((int) ($snapshot['longest_transaction_seconds'] ?? 0) >= $this->longTransactionWarningSeconds) {
            $level = $level === 'error' ? 'error' : 'warning';
            $reasons[] = 'long_transaction_detected';
        }

        if ((float) ($snapshot['sample_duration_ms'] ?? 0) >= $this->slowSampleWarningMs) {
            $level = $level === 'error' ? 'error' : 'warning';
            $reasons[] = 'sampling_slow';
        }

        return [$level, array_values(array_unique($reasons))];
    }

    /** @return array<string, mixed> */
    private function firstRow(array $rows): array
    {
        if (! isset($rows[0])) {
            return [];
        }

        return json_decode(json_encode($rows[0], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function decodeState($raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function logSamplingFailure(string $stage, Throwable $exception): void
    {
        try {
            $this->logger->error('PostgreSQL monitor sampling failed', [
                'connection' => $this->databaseConnection
                    ?? $this->databases->getDefaultConnection(),
                'stage' => $stage,
                'exception_class' => get_class($exception),
                'exception_code' => (int) $exception->getCode(),
            ]);
        } catch (Throwable) {
            // 日志通道失败不能反向中断 schedule:run。
        }
    }

    private function storeFailureState($stateConnection, array $previous, int $now): void
    {
        if (! is_object($stateConnection)) {
            return;
        }

        try {
            $state = [
                'schema_version' => 1,
                'level' => 'error',
                'reasons' => ['sampling_failed'],
                'snapshot' => is_array($previous['snapshot'] ?? null)
                    ? $previous['snapshot']
                    : [],
                'last_sampled_at' => max(0, (int) ($previous['last_sampled_at'] ?? 0)),
                'last_attempted_at' => $now,
                'last_failure_at' => $now,
            ];
            $stateConnection->setex(
                $this->stateKey,
                $this->stateTtlSeconds,
                json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            );
        } catch (Throwable) {
            // PostgreSQL 与状态 Redis 同时失败时只返回结构化失败，不能再产生异常风暴。
        }
    }

    private function nullableString($value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function coreSql(): string
    {
        return <<<'SQL'
WITH settings AS (
    SELECT COALESCE(max(setting::integer) FILTER (WHERE name = 'max_connections'), 0) AS max_connections,
           COALESCE(max(setting::integer) FILTER (WHERE name = 'superuser_reserved_connections'), 0) AS superuser_reserved_connections,
           COALESCE(max(setting::integer) FILTER (WHERE name = 'reserved_connections'), 0) AS reserved_connections
    FROM pg_settings
    WHERE name IN ('max_connections', 'superuser_reserved_connections', 'reserved_connections')
),
activity AS (
    SELECT count(*) FILTER (
               WHERE backend_type = 'client backend' AND pid <> pg_backend_pid()
           ) AS total_connections,
           count(*) FILTER (
               WHERE backend_type = 'client backend'
                 AND pid <> pg_backend_pid()
                 AND datname = current_database()
           ) AS current_database_connections,
           count(*) FILTER (
               WHERE backend_type = 'client backend'
                 AND pid <> pg_backend_pid()
                 AND state = 'active'
           ) AS active_connections,
           count(*) FILTER (
               WHERE backend_type = 'client backend'
                 AND pid <> pg_backend_pid()
                 AND state = 'idle'
           ) AS idle_connections,
           count(*) FILTER (
               WHERE backend_type = 'client backend'
                 AND pid <> pg_backend_pid()
                 AND state IN ('idle in transaction', 'idle in transaction (aborted)')
           ) AS idle_in_transaction_connections,
           count(*) FILTER (
               WHERE backend_type = 'client backend'
                 AND pid <> pg_backend_pid()
                 AND wait_event_type = 'Lock'
           ) AS lock_waiting_connections,
           COALESCE(max(EXTRACT(EPOCH FROM (clock_timestamp() - xact_start))) FILTER (
               WHERE backend_type = 'client backend'
                 AND pid <> pg_backend_pid()
                 AND xact_start IS NOT NULL
           ), 0)::bigint AS longest_transaction_seconds
    FROM pg_stat_activity
),
locks AS (
    SELECT count(DISTINCT pid) FILTER (
               WHERE NOT granted AND pid <> pg_backend_pid()
           ) AS blocked_connections
    FROM pg_locks
),
database_stats AS (
    SELECT deadlocks AS deadlocks_total,
           CASE
               WHEN (blks_hit + blks_read) > 0
                   THEN round((blks_hit::numeric * 100) / (blks_hit + blks_read), 2)
               ELSE NULL
           END AS cache_hit_percent
    FROM pg_stat_database
    WHERE datname = current_database()
)
SELECT current_database() AS database,
       settings.*,
       activity.*,
       locks.blocked_connections,
       database_stats.deadlocks_total,
       database_stats.cache_hit_percent
FROM settings
CROSS JOIN activity
CROSS JOIN locks
LEFT JOIN database_stats ON true
SQL;
    }

    private function locatorsSql(): string
    {
        return <<<'SQL'
SELECT stats.n_live_tup,
       stats.n_dead_tup,
       stats.n_tup_upd,
       stats.n_tup_hot_upd,
       stats.autovacuum_count,
       stats.autoanalyze_count,
       stats.last_autovacuum,
       stats.last_autoanalyze,
       pg_relation_size(stats.relid) AS table_bytes,
       pg_indexes_size(stats.relid) AS indexes_bytes,
       pg_total_relation_size(stats.relid) AS total_bytes
FROM pg_stat_user_tables AS stats
WHERE stats.relid = to_regclass('public.locators')
SQL;
    }
}
