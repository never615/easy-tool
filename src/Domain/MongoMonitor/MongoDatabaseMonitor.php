<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\MongoMonitor;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\ConnectionResolverInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * MongoDB 的低开销运行状态采样器。
 *
 * 每分钟只执行 ping、hello 和一次 serverStatus；不读取集合、文档或数据库体积统计。
 * serverStatus 权限不足时降级为可用性与拓扑采样，避免要求业务账号额外获得 clusterMonitor。
 * 结果写入共享 Redis，AppStats HTTP 请求不会直接连接 MongoDB。
 */
final class MongoDatabaseMonitor
{
    private readonly bool $enabled;

    private readonly ?string $databaseConnection;

    private readonly ?string $redisConnection;

    private readonly string $stateKey;

    private readonly int $stateTtlSeconds;

    private readonly int $maxTimeMs;

    private readonly float $warningPercent;

    private readonly float $errorPercent;

    /** @var callable(): int */
    private $clock;

    /** @var null|callable(object, array<string, mixed>): array<string, mixed> */
    private $commandRunner;

    public function __construct(
        private readonly ConnectionResolverInterface $databases,
        private readonly RedisFactory $redis,
        private readonly LoggerInterface $logger,
        array $config,
        ?callable $clock = null,
        ?callable $commandRunner = null
    ) {
        $this->enabled = (bool) ($config['enabled'] ?? false);
        $this->databaseConnection = $this->nullableString($config['connection'] ?? null);
        $this->redisConnection = $this->nullableString($config['state_redis_connection'] ?? null);
        $this->stateKey = (string) ($config['state_key'] ?? 'easy-tool:mongodb-monitor:state');
        $this->stateTtlSeconds = max(300, (int) ($config['state_ttl_seconds'] ?? 86400));
        $this->maxTimeMs = min(5000, max(100, (int) ($config['max_time_ms'] ?? 1000)));

        $warning = min(100.0, max(1.0, (float) ($config['warning_percent'] ?? 70)));
        $error = min(100.0, max(1.0, (float) ($config['error_percent'] ?? 85)));
        $this->warningPercent = min($warning, $error);
        $this->errorPercent = max($warning, $error);
        $this->clock = $clock ?? static fn (): int => time();
        $this->commandRunner = $commandRunner;
    }

    /** @return array<string, mixed> */
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

        try {
            $stateConnection = $this->redis->connection($this->redisConnection);
            $previous = $this->decodeState($stateConnection->get($this->stateKey));
            $connection = $this->databases->connection($this->databaseConnection);

            if ($connection->getDriverName() !== 'mongodb') {
                return [
                    'enabled' => true,
                    'ok' => false,
                    'level' => 'error',
                    'reason' => 'unsupported_driver',
                ];
            }

            $startedAt = hrtime(true);
            $this->command($connection, ['ping' => 1]);
            $hello = $this->command($connection, ['hello' => 1]);

            $serverStatus = null;
            try {
                $serverStatus = $this->command($connection, [
                    'serverStatus' => 1,
                    'maxTimeMS' => $this->maxTimeMs,
                    'metrics' => 0,
                    'locks' => 0,
                    'globalLock' => 0,
                    'tcmalloc' => 0,
                    'network' => 0,
                    'security' => 0,
                    'transactions' => 0,
                    'logicalSessionRecordCache' => 0,
                    'opLatencies' => 0,
                ]);
            } catch (Throwable) {
                // ping 与 hello 已成功时，serverStatus 权限不足只影响容量指标，不等于数据库不可用。
            }

            $sampleDurationMs = round((hrtime(true) - $startedAt) / 1_000_000, 2);
            $snapshot = $this->buildSnapshot(
                $hello,
                $serverStatus,
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
        } catch (Throwable) {
            $this->logSamplingFailure();
            $this->storeFailureState($stateConnection, $previous, $now);

            return [
                'enabled' => true,
                'ok' => false,
                'level' => 'error',
                'reason' => 'sampling_failed',
            ];
        }
    }

    /** @return array<string, mixed> */
    private function command($connection, array $command): array
    {
        if ($this->commandRunner !== null) {
            return ($this->commandRunner)($connection, $command);
        }

        // easy-tool 的 Composer 约束仍允许 5.1；5.2+ 使用正式 getDatabase()，
        // 只有旧版依赖才回退到当时尚未弃用的 getMongoDB()。
        $database = method_exists($connection, 'getDatabase')
            ? $connection->getDatabase()
            : $connection->getMongoDB();
        $rows = $database->command($command, [
            'typeMap' => [
                'root' => 'array',
                'document' => 'array',
                'array' => 'array',
            ],
        ])->toArray();

        return isset($rows[0]) && is_array($rows[0]) ? $rows[0] : [];
    }

    /** @return array<string, int|float|string|bool|null> */
    private function buildSnapshot(
        array $hello,
        ?array $serverStatus,
        array $previous,
        float $sampleDurationMs
    ): array {
        $connections = is_array($serverStatus['connections'] ?? null)
            ? $serverStatus['connections']
            : [];
        $memory = is_array($serverStatus['mem'] ?? null) ? $serverStatus['mem'] : [];
        $wiredTiger = is_array($serverStatus['wiredTiger']['cache'] ?? null)
            ? $serverStatus['wiredTiger']['cache']
            : [];
        $current = $this->integer($connections, 'current');
        $available = $this->integer($connections, 'available');
        $capacity = $current !== null && $available !== null ? $current + $available : null;
        $connectionUsedPercent = $capacity !== null && $capacity > 0 && $current !== null
            ? round(($current / $capacity) * 100, 2)
            : null;
        $cacheBytes = $this->integer($wiredTiger, 'bytes currently in the cache');
        $cacheMaxBytes = $this->integer(
            $wiredTiger,
            'maximum bytes configured'
        );
        $cacheUsedPercent = $cacheBytes !== null && $cacheMaxBytes !== null && $cacheMaxBytes > 0
            ? round(($cacheBytes / $cacheMaxBytes) * 100, 2)
            : null;
        $rejected = $this->integer($connections, 'rejected');
        $previousSnapshot = is_array($previous['snapshot'] ?? null)
            ? $previous['snapshot']
            : [];
        $previousRejected = isset($previousSnapshot['rejected_connections'])
            ? max(0, (int) $previousSnapshot['rejected_connections'])
            : $rejected;

        return [
            'monitoring_mode' => $serverStatus === null ? 'availability_only' : 'server_status',
            'version' => is_string($serverStatus['version'] ?? null)
                ? $serverStatus['version']
                : null,
            'process' => is_string($serverStatus['process'] ?? null)
                ? $serverStatus['process']
                : null,
            'uptime_seconds' => $this->integer($serverStatus ?? [], 'uptime'),
            'is_writable_primary' => (bool) ($hello['isWritablePrimary'] ?? false),
            'secondary' => (bool) ($hello['secondary'] ?? false),
            'replica_set_name' => is_string($hello['setName'] ?? null) ? $hello['setName'] : null,
            'current_connections' => $current,
            'available_connections' => $available,
            'connection_capacity' => $capacity,
            'connection_used_percent' => $connectionUsedPercent,
            'active_connections' => $this->integer($connections, 'active'),
            'rejected_connections' => $rejected,
            'rejected_connections_delta' => $rejected === null || $previousRejected === null
                ? null
                : max(0, $rejected - $previousRejected),
            'resident_memory_bytes' => $this->mebibytesToBytes($memory, 'resident'),
            'virtual_memory_bytes' => $this->mebibytesToBytes($memory, 'virtual'),
            'wiredtiger_cache_bytes' => $cacheBytes,
            'wiredtiger_cache_max_bytes' => $cacheMaxBytes,
            'wiredtiger_cache_used_percent' => $cacheUsedPercent,
            'wiredtiger_cache_dirty_bytes' => $this->integer(
                $wiredTiger,
                'tracked dirty bytes in the cache'
            ),
            'sample_duration_ms' => $sampleDurationMs,
        ];
    }

    /** @return array{0: string, 1: array<int, string>} */
    private function classify(array $snapshot): array
    {
        $level = 'normal';
        $reasons = [];

        foreach ([
            'connection_used_percent' => ['connection_usage_warning', 'connection_usage_error'],
        ] as $metric => [$warningReason, $errorReason]) {
            $value = $snapshot[$metric] ?? null;
            if (is_numeric($value) && (float) $value >= $this->errorPercent) {
                $level = 'error';
                $reasons[] = $errorReason;
            } elseif (is_numeric($value) && (float) $value >= $this->warningPercent) {
                $level = $level === 'error' ? 'error' : 'warning';
                $reasons[] = $warningReason;
            }
        }

        if ((int) ($snapshot['rejected_connections_delta'] ?? 0) > 0) {
            $level = 'error';
            $reasons[] = 'rejected_connection_detected';
        }
        if (($snapshot['monitoring_mode'] ?? null) === 'availability_only') {
            $level = $level === 'error' ? 'error' : 'warning';
            $reasons[] = 'server_status_unavailable';
        }

        return [$level, array_values(array_unique($reasons))];
    }

    private function integer(array $source, string $key): ?int
    {
        return isset($source[$key]) && is_numeric($source[$key])
            ? max(0, (int) $source[$key])
            : null;
    }

    private function mebibytesToBytes(array $source, string $key): ?int
    {
        $value = $this->integer($source, $key);

        return $value === null ? null : $value * 1024 * 1024;
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

    private function logSamplingFailure(): void
    {
        try {
            $this->logger->error('MongoDB monitor failed', [
                'connection' => $this->databaseConnection
                    ?? $this->databases->getDefaultConnection(),
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
            $stateConnection->setex(
                $this->stateKey,
                $this->stateTtlSeconds,
                json_encode([
                    'schema_version' => 1,
                    'level' => 'error',
                    'reasons' => ['sampling_failed'],
                    'snapshot' => is_array($previous['snapshot'] ?? null)
                        ? $previous['snapshot']
                        : [],
                    'last_sampled_at' => max(0, (int) ($previous['last_sampled_at'] ?? 0)),
                    'last_attempted_at' => $now,
                    'last_failure_at' => $now,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            );
        } catch (Throwable) {
            // MongoDB 与状态 Redis 同时失败时只返回结构化失败。
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
}
