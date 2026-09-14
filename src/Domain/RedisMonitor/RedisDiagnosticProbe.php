<?php

namespace Mallto\Tool\Domain\RedisMonitor;

use Closure;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Throwable;

/**
 * Redis 诊断的单次只读探针。
 *
 * 探针只执行 PING、分段 INFO 和固定队列的 O(1) 长度查询。输出不会包含端点、凭据、
 * 异常原文、业务 Key 或任务 payload，适合直接归档为客户侧证据。
 */
class RedisDiagnosticProbe
{
    private const INFO_FIELDS = [
        'server' => ['run_id', 'uptime_in_seconds'],
        'replication' => ['role'],
        'memory' => [
            'used_memory', 'used_memory_rss', 'maxmemory', 'maxmemory_policy',
            'mem_fragmentation_ratio',
        ],
        'stats' => [
            'total_net_input_bytes', 'total_net_output_bytes',
            'instantaneous_input_kbps', 'instantaneous_output_kbps',
            'total_net_repl_input_bytes', 'total_net_repl_output_bytes',
            'instantaneous_input_repl_kbps', 'instantaneous_output_repl_kbps',
            'instantaneous_ops_per_sec', 'total_commands_processed',
            'rejected_connections', 'total_error_replies', 'evicted_keys',
        ],
        'clients' => ['connected_clients', 'blocked_clients', 'maxclients'],
        'cpu' => [
            'used_cpu_sys', 'used_cpu_user', 'used_cpu_sys_main_thread',
            'used_cpu_user_main_thread',
        ],
    ];

    private readonly Closure $monotonicClock;

    private readonly Closure $wallClock;

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly bool $queueComparable,
        ?Closure $monotonicClock = null,
        ?Closure $wallClock = null
    ) {
        $this->monotonicClock = $monotonicClock ?? static fn (): int => hrtime(true);
        $this->wallClock = $wallClock ?? static fn (): string => date(DATE_ATOM);
    }

    /** @return array<string, mixed> */
    public function sample(string $connectionName): array
    {
        $sample = [
            'captured_at' => ($this->wallClock)(),
            'monotonic_ns' => ($this->monotonicClock)(),
            'connection' => $connectionName,
            'available' => false,
            'stages' => [],
            'metrics' => [],
        ];

        $connection = null;
        $connectionAttempt = $this->attempt(function () use ($connectionName, &$connection) {
            $connection = $this->redis->connection($connectionName);

            return true;
        });
        $sample['stages']['connection'] = $connectionAttempt['stage'];

        if (! $connectionAttempt['ok']) {
            $this->purge($connectionName);

            return $sample;
        }

        try {
            $ping = $this->attempt(static fn () => $connection->command('PING'));
            $sample['stages']['ping'] = $ping['stage'];

            foreach (array_keys(self::INFO_FIELDS) as $section) {
                $attempt = $this->attempt(
                    static fn () => $connection->command('INFO', [$section])
                );
                $sample['stages']['info_'.$section] = $attempt['stage'];

                if (! $attempt['ok']) {
                    continue;
                }

                $flat = $this->flattenInfo($attempt['value']);
                foreach (self::INFO_FIELDS[$section] as $field) {
                    if (! array_key_exists($field, $flat)) {
                        continue;
                    }

                    if ($field === 'run_id') {
                        $sample['metrics']['run_id_hash'] = substr(
                            hash('sha256', (string) $flat[$field]),
                            0,
                            12
                        );
                    } else {
                        $sample['metrics'][$field] = $this->normalizeMetric($flat[$field]);
                    }
                }
            }

            $sample['available'] = (bool) ($ping['ok'] ?? false)
                && isset($sample['metrics']['total_net_output_bytes']);
        } finally {
            try {
                $connection->disconnect();
            } catch (Throwable) {
                // 诊断连接清理失败不能覆盖已经采集的结果。
            }
            $this->purge($connectionName);
        }

        return $sample;
    }

    /** @param array<int, string> $queueNames */
    public function queueSizes(array $queueNames): array
    {
        if (! $this->queueComparable) {
            return [
                'comparable' => false,
                'reason' => 'queue_redis_endpoint_differs',
                'sizes' => [],
            ];
        }

        $connection = null;
        $connectionAttempt = $this->attempt(function () use (&$connection) {
            $connection = $this->redis->connection('relaxed');

            return true;
        });
        if (! $connectionAttempt['ok']) {
            $this->purge('relaxed');

            return [
                'comparable' => true,
                'sizes' => [],
                'error' => $connectionAttempt['stage'],
            ];
        }

        $sizes = [];
        try {
            foreach ($queueNames as $queueName) {
                $queueKey = 'queues:'.$queueName;
                $attempt = $this->attempt(static fn () => [
                    'ready' => (int) $connection->command('LLEN', [$queueKey]),
                    'delayed' => (int) $connection->command('ZCARD', [$queueKey.':delayed']),
                    'reserved' => (int) $connection->command('ZCARD', [$queueKey.':reserved']),
                ]);
                if (! $attempt['ok']) {
                    $sizes[$queueName] = ['ok' => false, 'size' => null] + $attempt['stage'];

                    continue;
                }

                $parts = $attempt['value'];
                $sizes[$queueName] = [
                    'ok' => true,
                    'size' => array_sum($parts),
                    'ready' => $parts['ready'],
                    'delayed' => $parts['delayed'],
                    'reserved' => $parts['reserved'],
                    'duration_ms' => $attempt['stage']['duration_ms'],
                ];
            }
        } finally {
            try {
                $connection->disconnect();
            } catch (Throwable) {
                // 诊断连接清理失败不能覆盖已经采集的队列结果。
            }
            $this->purge('relaxed');
        }

        return ['comparable' => true, 'sizes' => $sizes];
    }

    /** @return array{ok: bool, value?: mixed, stage: array<string, mixed>} */
    private function attempt(Closure $operation): array
    {
        $started = ($this->monotonicClock)();

        try {
            $value = $operation();

            return [
                'ok' => true,
                'value' => $value,
                'stage' => [
                    'ok' => true,
                    'duration_ms' => $this->elapsedMilliseconds($started),
                ],
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'stage' => [
                    'ok' => false,
                    'duration_ms' => $this->elapsedMilliseconds($started),
                    'error_kind' => $this->errorKind($exception),
                    'exception_class' => get_class($exception),
                    'exception_code' => (int) $exception->getCode(),
                ],
            ];
        }
    }

    private function elapsedMilliseconds(int $started): float
    {
        return round(max(0, ($this->monotonicClock)() - $started) / 1_000_000, 3);
    }

    private function errorKind(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'noperm') || str_contains($message, 'permission')) {
            return 'permission_denied';
        }
        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return 'timeout';
        }
        if (str_contains($message, 'read error') || str_contains($message, 'socket')) {
            return 'connection_read_error';
        }
        if (str_contains($message, 'refused') || str_contains($message, 'unreachable')) {
            return 'connection_unavailable';
        }

        return 'unexpected_error';
    }

    private function flattenInfo(mixed $raw): array
    {
        if (is_string($raw)) {
            $flat = [];
            foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
                if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, ':')) {
                    continue;
                }
                [$key, $value] = explode(':', $line, 2);
                $flat[trim($key)] = trim($value);
            }

            return $flat;
        }

        if (! is_array($raw)) {
            return [];
        }

        $flat = [];
        foreach ($raw as $key => $value) {
            if (is_array($value)) {
                $flat = array_merge($flat, $this->flattenInfo($value));
            } elseif (is_string($key)) {
                $flat[$key] = $value;
            }
        }

        return $flat;
    }

    private function normalizeMetric(mixed $value): int|float|string
    {
        if (is_numeric($value)) {
            return str_contains((string) $value, '.') ? (float) $value : (int) $value;
        }

        return (string) $value;
    }

    private function purge(string $connectionName): void
    {
        if (method_exists($this->redis, 'purge')) {
            $this->redis->purge($connectionName);
        }
    }
}
