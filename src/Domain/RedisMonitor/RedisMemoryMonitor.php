<?php

namespace Mallto\Tool\Domain\RedisMonitor;

use Closure;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * 共享 Redis 的低开销内存监控器。
 *
 * 每次 run() 严格执行四类操作：INFO memory、INFO stats、读取一个状态 Key、带 TTL 写回
 * 同一个状态 Key。禁止 SCAN、KEYS、BIGKEYS、MEMORY USAGE 或逐 Key 分析，因此运行开销
 * 不随业务 Key 数量增长。它由 Laravel Scheduler 每分钟调用一次，不包含无限循环，也不会
 * 派发 Horizon Job 或 Swoole Task。
 */
final class RedisMemoryMonitor
{
    private const LEVEL_NORMAL = 'normal';

    private const LEVEL_WARNING = 'warning';

    private const LEVEL_ERROR = 'error';

    private readonly bool $enabled;

    private readonly string $connectionName;

    private readonly float $warningPercent;

    private readonly float $errorPercent;

    private readonly int $logCooldownSeconds;

    private readonly string $stateKey;

    private readonly int $stateTtlSeconds;

    private readonly Closure $clock;

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly LoggerInterface $logger,
        array $config,
        ?Closure $clock = null
    ) {
        $this->enabled = (bool) ($config['enabled'] ?? false);
        $this->connectionName = (string) ($config['connection'] ?? 'redis-monitor');

        // 阈值在运行时做最后一道约束，避免错误配置为负数、超过 100 或 warning 高于 error。
        $warning = min(100.0, max(1.0, (float) ($config['warning_percent'] ?? 70)));
        $error = min(100.0, max(1.0, (float) ($config['error_percent'] ?? 90)));
        $this->warningPercent = min($warning, $error);
        $this->errorPercent = max($warning, $error);

        $this->logCooldownSeconds = max(1, (int) ($config['log_cooldown_seconds'] ?? 600));
        $this->stateKey = (string) ($config['state_key'] ?? 'easy-tool:redis-memory-monitor:state');
        // TTL 至少覆盖一个冷却窗口；即使 allkeys-lru 提前淘汰，也只会多写一次日志。
        $this->stateTtlSeconds = max(
            $this->logCooldownSeconds,
            (int) ($config['state_ttl_seconds'] ?? 86400)
        );
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * 执行一次采样并返回命令可序列化的结果。
     *
     * 任何连接、INFO 解析、状态读写或日志异常都在本方法内收口；Scheduler 不会收到异常。
     */
    public function run(): array
    {
        if (! $this->enabled) {
            return [
                'enabled' => false,
                'ok' => true,
                'level' => 'disabled',
                'logged' => false,
            ];
        }

        try {
            // 只解析一次命名连接，确保 INFO 与状态 Key 位于同一个共享 Redis/逻辑 DB。
            $connection = $this->redis->connection($this->connectionName);
            $memory = $this->normalizeInfo($connection->command('INFO', ['memory']));
            $stats = $this->normalizeInfo($connection->command('INFO', ['stats']));
            $snapshot = RedisMemorySnapshot::fromInfo($memory, $stats);
            $previousState = $this->decodeState($connection->get($this->stateKey));
            $now = ($this->clock)();

            [$level, $reasons, $evictedKeysDelta] = $this->classify($snapshot, $previousState);
            $logged = $this->logWhenNeeded(
                $level,
                $reasons,
                $snapshot,
                $evictedKeysDelta,
                $previousState,
                $now
            );

            $connection->setex(
                $this->stateKey,
                $this->stateTtlSeconds,
                json_encode(
                    $this->nextState(
                        $level,
                        $reasons,
                        $snapshot,
                        $previousState,
                        $logged,
                        $now
                    ),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
            );

            return [
                'enabled' => true,
                'ok' => true,
                'level' => $level,
                'logged' => $logged,
                'reasons' => $reasons,
                'snapshot' => $snapshot->toArray(),
            ];
        } catch (Throwable) {
            // 不把异常消息放入日志上下文，避免底层客户端把 Redis 主机、凭证或命令参数拼入。
            // connection 名是固定配置名，足够用于定位；具体连接错误仍可从 Redis/容器日志查看。
            try {
                $this->logger->error('Redis memory monitor failed', [
                    'connection' => $this->connectionName,
                ]);
            } catch (Throwable) {
                // 日志通道自身失败也不能反向中断 schedule:run。
            }

            return [
                'enabled' => true,
                'ok' => false,
                'level' => self::LEVEL_ERROR,
                'logged' => true,
                'reason' => 'sampling_failed',
            ];
        }
    }

    /**
     * 同时兼容 phpredis 的关联数组、嵌套数组和 Predis/代理返回的原始 INFO 文本。
     */
    private function normalizeInfo($raw): array
    {
        if (is_string($raw)) {
            $normalized = [];
            foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
                if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, ':')) {
                    continue;
                }

                [$key, $value] = explode(':', $line, 2);
                $normalized[trim($key)] = trim($value);
            }

            return $normalized;
        }

        if (! is_array($raw)) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $key => $value) {
            if (is_array($value)) {
                $normalized = array_merge($normalized, $this->normalizeInfo($value));

                continue;
            }

            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function decodeState($raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        // 状态 Key 允许被 allkeys-lru 淘汰或因旧版本格式变化而失效；无法解析时按首次采样处理。
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{0: string, 1: array<int, string>, 2: int} */
    private function classify(RedisMemorySnapshot $snapshot, array $previousState): array
    {
        $level = self::LEVEL_NORMAL;
        $reasons = [];
        $usedPercent = $snapshot->usedPercent();

        if ($snapshot->maxMemory <= 0) {
            $level = self::LEVEL_ERROR;
            $reasons[] = 'maxmemory_not_configured';
        } elseif ($usedPercent >= $this->errorPercent) {
            $level = self::LEVEL_ERROR;
            $reasons[] = 'memory_usage_error';
        } elseif ($usedPercent >= $this->warningPercent) {
            $level = self::LEVEL_WARNING;
            $reasons[] = 'memory_usage_warning';
        }

        // Redis 重启后累计计数可能回到 0；只把相对上一轮的正增量视为本周期淘汰。
        $previousEvictedKeys = array_key_exists('evicted_keys', $previousState)
            ? max(0, (int) $previousState['evicted_keys'])
            : $snapshot->evictedKeys;
        $evictedKeysDelta = max(0, $snapshot->evictedKeys - $previousEvictedKeys);
        if ($evictedKeysDelta > 0) {
            $level = self::LEVEL_ERROR;
            $reasons[] = 'evicted_keys_increased';
        }

        return [$level, $reasons, $evictedKeysDelta];
    }

    private function logWhenNeeded(
        string $level,
        array $reasons,
        RedisMemorySnapshot $snapshot,
        int $evictedKeysDelta,
        array $previousState,
        int $now
    ): bool {
        $previousLevel = (string) ($previousState['level'] ?? '');

        if ($level === self::LEVEL_NORMAL) {
            if (! in_array($previousLevel, [self::LEVEL_WARNING, self::LEVEL_ERROR], true)) {
                return false;
            }

            $this->logger->info(
                'Redis memory pressure recovered',
                $this->logContext($snapshot, $evictedKeysDelta)
            );

            return true;
        }

        $reasonsHash = $this->reasonsHash($reasons);
        $lastLoggedAt = max(0, (int) ($previousState['last_logged_at'] ?? 0));
        $lastLoggedUsage = $previousState['last_logged_usage_percent'] ?? null;
        $usage = $snapshot->usedPercent();
        $worsenedByFivePoints = $usage !== null
            && is_numeric($lastLoggedUsage)
            && $usage >= ((float) $lastLoggedUsage + 5.0);

        $shouldLog = $previousLevel !== $level
            || $lastLoggedAt === 0
            || ($now - $lastLoggedAt) >= $this->logCooldownSeconds
            || $evictedKeysDelta > 0
            || $worsenedByFivePoints
            || (string) ($previousState['last_logged_reasons_hash'] ?? '') !== $reasonsHash;

        if (! $shouldLog) {
            return false;
        }

        $context = $this->logContext($snapshot, $evictedKeysDelta);
        if ($level === self::LEVEL_ERROR) {
            $this->logger->error('Redis memory pressure error', $context);
        } else {
            $this->logger->warning('Redis memory pressure warning', $context);
        }

        return true;
    }

    private function nextState(
        string $level,
        array $reasons,
        RedisMemorySnapshot $snapshot,
        array $previousState,
        bool $logged,
        int $now
    ): array {
        return [
            'level' => $level,
            'evicted_keys' => $snapshot->evictedKeys,
            'rejected_connections' => $snapshot->rejectedConnections,
            'last_logged_at' => $logged ? $now : max(0, (int) ($previousState['last_logged_at'] ?? 0)),
            'last_logged_usage_percent' => $logged
                ? $snapshot->usedPercent()
                : ($previousState['last_logged_usage_percent'] ?? null),
            'last_logged_reasons_hash' => $logged
                ? $this->reasonsHash($reasons)
                : (string) ($previousState['last_logged_reasons_hash'] ?? ''),
            'last_sampled_at' => $now,
        ];
    }

    /**
     * 日志上下文只包含固定连接名与数值。不得加入 state_key、密码、业务 Key 或 payload。
     */
    private function logContext(RedisMemorySnapshot $snapshot, int $evictedKeysDelta): array
    {
        return [
            'connection' => $this->connectionName,
            'used_memory' => $snapshot->usedMemory,
            'used_memory_rss' => $snapshot->usedMemoryRss,
            'maxmemory' => $snapshot->maxMemory,
            'used_percent' => $snapshot->usedPercent(),
            'mem_fragmentation_ratio' => $snapshot->memFragmentationRatio,
            'evicted_keys' => $snapshot->evictedKeys,
            'evicted_keys_delta' => $evictedKeysDelta,
            'rejected_connections' => $snapshot->rejectedConnections,
        ];
    }

    private function reasonsHash(array $reasons): string
    {
        return hash('sha256', json_encode(array_values($reasons)));
    }
}
