<?php

namespace Mallto\Tool\Domain\RuntimeConfig;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\RetrievingManyKeys;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Sleep;
use Throwable;

final class RuntimeConfigSharedStore
{
    public function __construct(private readonly CacheFactory $cache)
    {
    }

    /** @return array{identity: string, found: bool, value: mixed}|null */
    public function get(RuntimeConfigIdentity $identity): ?array
    {
        try {
            return $this->validPayload($identity, $this->store()->get($identity->redisKey()));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * 通过一次缓存操作读取独立身份（Redis 使用 MGET）。
     * 无效或缺失条目仍视为未命中，并保留现有的逐身份回填锁。
     * @param array<string, RuntimeConfigIdentity> $identities
     * @return array<string, array|null>
     */
    public function getMany(array $identities): array
    {
        if ($identities === []) {
            return [];
        }
        try {
            $keys = array_map(static fn (RuntimeConfigIdentity $identity): string => $identity->redisKey(), $identities);
            $values = $this->store()->many(array_values($keys));
            $result = [];
            foreach ($identities as $key => $identity) {
                $result[$key] = $this->validPayload($identity, $values[$keys[$key]] ?? null);
            }
            return $result;
        } catch (Throwable) {
            return array_fill_keys(array_keys($identities), null);
        }
    }

    /**
     * 通过一次 MGET 观测一个配置及其数组形式的配套配置。
     * 仅两个有效命中可以复用。遇到不支持的存储、监听器、未命中或
     * 错误时，两个原有读取和回填路径仍各自负责自身语义。
     * 不跨调用保留任何值，也不改变 TTL 或回填锁。
     * @return ?array{config: array, companion: array}
     */
    public function getWithArray(RuntimeConfigIdentity $identity, object $companion, string $key): ?array
    {
        try {
            $repository = $this->store();
            if (get_class($repository) !== Repository::class || get_class($companion) !== Repository::class) {
                return null;
            }
            $a = $repository->getStore();
            $b = $companion->getStore();
            if (get_class($a) !== RedisStore::class || get_class($b) !== RedisStore::class
                || $a->getRedis() !== $b->getRedis() || $a->getPrefix() !== $b->getPrefix()) {
                return null;
            }
            // 复用相同连接对象，保留前缀、认证、
            // 超时与失败策略。让 RedisStore 解码受支持的值。
            $connection = $a->connection();
            if ($connection !== $b->connection()) {
                return null;
            }
            if (!in_array(get_class($connection), [PhpRedisConnection::class, \Mallto\Tool\Domain\RedisMonitor\RecoveringPhpRedisConnection::class], true)) {
                return null;
            }
            $client = $connection->client();
            if (get_class($client) !== \Redis::class || $client->getMode() !== \Redis::ATOMIC
                || $client->getOption(\Redis::OPT_SERIALIZER) !== \Redis::SERIALIZER_NONE
                || (defined('Redis::OPT_COMPRESSION')
                    && $client->getOption(\Redis::OPT_COMPRESSION) !== \Redis::COMPRESSION_NONE)) {
                return null;
            }
            foreach ([$repository->getEventDispatcher(), $companion->getEventDispatcher()] as $dispatcher) {
                if (!$this->noReadListeners($dispatcher, [RetrievingKey::class, RetrievingManyKeys::class,
                    CacheHit::class, CacheMissed::class])) {
                    return null;
                }
            }
            if (!$this->noReadListeners($connection->getEventDispatcher(), [CommandExecuted::class])) {
                return null;
            }
            $raw = $connection->mget([$a->getPrefix() . $identity->redisKey(), $a->getPrefix() . $key]);
            if (!is_array($raw) || count($raw) !== 2) {
                return null;
            }
            // 检查时不触发外部 __wakeup 钩子。被丢弃的
            // 观测必须将反序列化留给原始 GET，且仅执行一次。
            $configShape = $this->arrayShape($raw[0]);
            $bindingShape = $this->arrayShape($raw[1]);
            if ($this->validPayload($identity, $configShape) === null || $bindingShape === null
                || !$this->scalarTree($configShape) || !$this->scalarTree($bindingShape)) {
                return null;
            }
            $payload = $this->validPayload($identity, RuntimeConfigPairedRedisDecoder::decode($a, $raw[0], $connection));
            $binding = RuntimeConfigPairedRedisDecoder::decode($b, $raw[1], $connection);
            return $payload !== null && is_array($binding)
                ? ['config' => $payload, 'companion' => $binding] : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function arrayShape(mixed $raw): ?array
    {
        $shape = is_string($raw) && str_starts_with($raw, 'a:')
            ? @unserialize($raw, ['allowed_classes' => false]) : null;
        return is_array($shape) ? $shape : null;
    }

    private function scalarTree(mixed $value, int $depth = 0): bool
    {
        if (!is_array($value)) {
            return is_scalar($value) || $value === null;
        }
        if ($depth >= 16 || count($value) > 4096) {
            return false;
        }
        foreach ($value as $item) {
            if (!$this->scalarTree($item, $depth + 1)) {
                return false;
            }
        }
        return true;
    }

    private function noReadListeners(?object $dispatcher, array $events): bool
    {
        if ($dispatcher === null) {
            return true;
        }
        if (get_class($dispatcher) !== Dispatcher::class) {
            return false;
        }
        foreach ($events as $event) {
            // 包括通配符及接口监听器；合并读取不得
            // 改变应用可观测的缓存或命令事件行为。
            if ($dispatcher->getListeners($event)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param callable(): array{found: bool, value: mixed} $loader
     * @param null|callable(): ?array $localReader 仅使用调用方认可的现有 L1，不进行共享 I/O。
     * @param null|callable(array): void $localPublisher 在解锁可能让出执行权之前发布调用方认可的 L1。
     * @return array{identity: string, found: bool, value: mixed}
     */
    public function remember(
        RuntimeConfigIdentity $identity, callable $loader,
        ?callable $localReader = null, ?callable $localPublisher = null
    ): array
    {
        $trace = RuntimeConfigRefillTrace::start($identity);
        $outcome = 'exception';
        $loaderStarted = false;
        $loadedPayload = null;
        $store = null;

        try {
            $store = $this->store();
            $seconds = max(1, (int)config('runtime_config.identity_lock_seconds', 5));

            $lock = $store->lock($this->lockKey($identity), $seconds);
            $deadline = hrtime(true) + $seconds * 1_000_000_000;
            $pollMicroseconds = 10_000;

            // 冷缓存读取者共享首次回填，不在已变热的键后串行等待。
            // fresh() 仍获取同一把锁，并始终在提交后替换该值。
            while (true) {
                // 当前读取者等待期间，其他工作进程可能已填充调用方的共享内存 L1。
                // 再次请求 Redis 锁或读取前先复用该值；
                // L1 缺失、无效或不可用时，保留原有协议。
                if (($local = $this->readLocal($identity, $localReader)) !== null) {
                    $outcome = 'local_cache_hit';
                    return $local;
                }
                if ($lock->get()) {
                    break;
                }
                $trace?->lockMiss();
                // 锁请求可能让出执行权，此时持有者可能发布 L1。
                // 增加一次共享缓存往返前先复查。
                if (($local = $this->readLocal($identity, $localReader)) !== null) {
                    $outcome = 'local_cache_hit';
                    return $local;
                }
                $cached = $this->validPayload($identity, $store->get($identity->redisKey()));
                if ($cached !== null) {
                    $outcome = 'contended_cache_hit';
                    return $cached;
                }
                // 共享缓存未命中可能发生在持有者于本次请求期间填充 L1 之前。
                // 已有可用值时不要继续休眠等待。
                if (($local = $this->readLocal($identity, $localReader)) !== null) {
                    $outcome = 'local_cache_hit';
                    return $local;
                }
                if (hrtime(true) + 250_000_000 >= $deadline) {
                    throw new LockTimeoutException();
                }
                $sleepStarted = $trace?->begin();
                try {
                    // 短暂回填不能让实时 Task 阻塞 250 毫秒。
                    // 退避到之前的上限，限制慢数据源的轮询成本。
                    Sleep::usleep($pollMicroseconds);
                    $pollMicroseconds = min(250_000, $pollMicroseconds * 2);
                } finally {
                    $trace?->slept($sleepStarted);
                }
            }

            try {
                $cached = $this->validPayload($identity, $store->get($identity->redisKey()));
                if ($cached !== null) {
                    $outcome = 'locked_cache_hit';
                    $this->publishLocal($localPublisher, $cached);
                    return $cached;
                }

                $loaderStarted = true;
                $loadStarted = $trace?->begin();
                try {
                    $loadedPayload = $this->payload($identity, $loader());
                } finally {
                    $trace?->loaded($loadStarted);
                }
                try {
                    $this->putWithStore($store, $identity, $loadedPayload);
                } catch (Throwable) {
                    // Redis 写入失败时，已加载的数据库值仍然正确。
                }

                $outcome = 'loader';
                // 释放锁等待 Redis 响应期间，跟随者可以使用已完成的值。
                // 并发失效后，调用方不得重新发布该值。
                $this->publishLocal($localPublisher, $loadedPayload);
                return $loadedPayload;
            } finally {
                $lock->release();
            }
        } catch (Throwable $exception) {
            if ($loaderStarted) {
                if ($loadedPayload !== null) {
                    $outcome = 'loaded_after_cache_error';
                    return $loadedPayload;
                }

                throw $exception;
            }

            if ($store !== null) {
                try {
                    $cached = $this->validPayload($identity, $store->get($identity->redisKey()));
                    if ($cached !== null) {
                        $outcome = 'fallback_cache_hit';
                        return $cached;
                    }
                } catch (Throwable) {
                    // 最终复查共享缓存仍未命中后，才继续读取数据源。
                }
            }

            $loadStarted = $trace?->begin();
            try {
                $payload = $this->payload($identity, $loader());
            } finally {
                $trace?->loaded($loadStarted);
            }
            $this->put($identity, $payload);

            $outcome = 'fallback_loader';
            return $payload;
        } finally {
            $trace?->finish($outcome);
        }
    }

    private function readLocal(RuntimeConfigIdentity $identity, ?callable $reader): ?array
    {
        if ($reader === null) {
            return null;
        }
        try {
            return $this->validPayload($identity, $reader());
        } catch (Throwable) {
            return null;
        }
    }

    private function publishLocal(?callable $publisher, array $payload): void
    {
        if ($publisher !== null) {
            try {
                $publisher($payload);
            } catch (Throwable) {
                // L1 不可用时不得修改值或重试数据源。
            }
        }
    }

    /**
     * @param callable(): array{found: bool, value: mixed} $loader
     * @return array{identity: string, found: bool, value: mixed}
     */
    public function fresh(RuntimeConfigIdentity $identity, callable $loader): array
    {
        $loaderStarted = false;
        $loadedPayload = null;

        try {
            $store = $this->store();
            $seconds = max(1, (int)config('runtime_config.identity_lock_seconds', 5));

            return $store->lock($this->lockKey($identity), $seconds)->block(
                $seconds,
                function () use ($store, $identity, $loader, &$loaderStarted, &$loadedPayload): array {
                    $loaderStarted = true;
                    $loadedPayload = $this->payload($identity, $loader());
                    try {
                        $this->putWithStore($store, $identity, $loadedPayload);
                    } catch (Throwable) {
                        // Redis 写入失败时，已加载的数据库值仍然正确。
                    }

                    return $loadedPayload;
                }
            );
        } catch (Throwable $exception) {
            if ($loaderStarted) {
                if ($loadedPayload !== null) {
                    return $loadedPayload;
                }

                throw $exception;
            }

            $payload = $this->payload($identity, $loader());
            $this->put($identity, $payload);

            return $payload;
        }
    }

    /** @param array{identity: string, found: bool, value: mixed} $payload */
    public function put(RuntimeConfigIdentity $identity, array $payload): void
    {
        try {
            $this->putWithStore($this->store(), $identity, $payload);
        } catch (Throwable) {
            // 共享缓存不可用时，PostgreSQL 仍是真实数据源。
        }
    }

    private function store()
    {
        return $this->cache->store((string)config('runtime_config.shared_store', 'redis'));
    }

    private function lockKey(RuntimeConfigIdentity $identity): string
    {
        return 'runtime_config:lock:' . $identity->fingerprint();
    }

    /**
     * @param mixed $loaded
     * @return array{identity: string, found: bool, value: mixed}
     */
    private function payload(RuntimeConfigIdentity $identity, $loaded): array
    {
        return [
            'identity' => $identity->fingerprint(),
            'found' => (bool)($loaded['found'] ?? false),
            'value' => $loaded['value'] ?? null,
        ];
    }

    /** @return array{identity: string, found: bool, value: mixed}|null */
    private function validPayload(RuntimeConfigIdentity $identity, $payload): ?array
    {
        if (!is_array($payload)
            || count($payload) !== 3
            || !array_key_exists('identity', $payload)
            || !array_key_exists('found', $payload)
            || !array_key_exists('value', $payload)
            || $payload['identity'] !== $identity->fingerprint()
            || !is_bool($payload['found'])) {
            return null;
        }

        return $payload;
    }

    /** @param array{identity: string, found: bool, value: mixed} $payload */
    private function putWithStore($store, RuntimeConfigIdentity $identity, array $payload): void
    {
        $store->put(
            $identity->redisKey(),
            $payload,
            max(1, (int)config('runtime_config.shared_ttl_seconds', 60))
        );
    }
}

/** 复用 Laravel 按连接处理的编解码器，不维护另一套解码器。 */
final class RuntimeConfigPairedRedisDecoder extends RedisStore
{
    public static function decode(RedisStore $store, mixed $raw, PhpRedisConnection $connection): mixed
    {
        return $raw === null || $raw === false ? null : $store->connectionAwareUnserialize($raw, $connection);
    }
}
