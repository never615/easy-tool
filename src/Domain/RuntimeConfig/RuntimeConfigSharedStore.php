<?php

namespace Mallto\Tool\Domain\RuntimeConfig;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\RetrievingManyKeys;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\PhpRedisLock;
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
     * Read independent identities in one cache operation (MGET on Redis).
     * Invalid/missing entries remain misses and retain the existing per-identity refill lock.
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
     * Observe one config and an array-valued companion in a single MGET.
     * Only two valid hits are reusable. Unsupported stores, listeners, misses and
     * errors leave both original read/refill paths responsible for their semantics.
     * No values survive this call and no TTL or refill lock is changed.
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
            // Identical connection objects preserve prefix, authentication,
            // timeouts and failure policy. Let RedisStore decode supported values.
            $connection = $a->connection();
            if ($connection !== $b->connection()) {
                return null;
            }
            if (get_class($connection) !== PhpRedisConnection::class) {
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
            // Inspect without invoking foreign __wakeup hooks. A discarded
            // observation must leave deserialization to the original GET once.
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

    /**
     * Collapse only the stock Redis lock/read round trip. The same owner, TTL,
     * locked recheck and compare-owner release remain in force; fresh is unchanged.
     * Unsupported adapters/listeners keep their original method calls.
     * @return ?array{owned: bool, observed: bool, payload: ?array}
     */
    private function acquireWithObservation($repository, $lock, RuntimeConfigIdentity $identity, int $seconds): ?array
    {
        $invoked = false;
        try {
            if (get_class($repository) !== Repository::class || get_class($lock) !== PhpRedisLock::class) return null;
            $store = $repository->getStore();
            if (get_class($store) !== RedisStore::class) return null;
            $connection = $store->connection();
            if ($connection !== $store->lockConnection() || get_class($connection) !== PhpRedisConnection::class) return null;
            $client = $connection->client();
            if (get_class($client) !== \Redis::class || $client->getMode() !== \Redis::ATOMIC
                || $client->getOption(\Redis::OPT_SERIALIZER) !== \Redis::SERIALIZER_NONE
                || (defined('Redis::OPT_COMPRESSION')
                    && $client->getOption(\Redis::OPT_COMPRESSION) !== \Redis::COMPRESSION_NONE)) return null;
            if (!$this->noReadListeners($repository->getEventDispatcher(), [RetrievingKey::class,
                    RetrievingManyKeys::class, CacheHit::class, CacheMissed::class])
                || !$this->noReadListeners($connection->getEventDispatcher(), [CommandExecuted::class])) return null;
            $invoked = true;
            $raw = $connection->eval(
                "local owned=redis.call('SET',KEYS[1],ARGV[1],'EX',ARGV[2],'NX');"
                . "return {owned and 1 or 0,redis.call('GET',KEYS[2])}", 2,
                $store->getPrefix() . $this->lockKey($identity),
                $store->getPrefix() . $identity->redisKey(), $lock->owner(), $seconds);
            if (!is_array($raw) || count($raw) !== 2 || !in_array($raw[0], [0, 1], true)
                || !(is_string($raw[1]) || $raw[1] === false || $raw[1] === null)) {
                throw new \UnexpectedValueException('Invalid runtime refill lock/read observation');
            }
            // Foreign serialized objects must be decoded by the ordinary GET once,
            // after ownership is handled. Never invoke their hooks speculatively.
            $shape = $this->arrayShape($raw[1]);
            $safe = $raw[1] === false || $raw[1] === null
                || ($this->validPayload($identity, $shape) !== null && $this->scalarTree($shape));
            return ['owned' => $raw[0] === 1, 'observed' => $safe,
                'payload' => $safe ? $this->validPayload($identity,
                    RuntimeConfigPairedRedisDecoder::decode($store, $raw[1], $connection)) : null];
        } catch (Throwable $error) {
            if (!$invoked) return null;
            // A lost reply may have acquired the lock. Release only our owner;
            // never replay the mutating EVAL or leave it until its lease expires.
            try { $lock->release(); } catch (Throwable) {}
            throw $error;
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
            // Includes wildcard and interface listeners; combining reads must not
            // change application-observable cache/command event behavior.
            if ($dispatcher->getListeners($event)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param callable(): array{found: bool, value: mixed} $loader
     * @param null|callable(): ?array $localReader Existing caller-approved L1 only; never performs shared I/O.
     * @param null|callable(array): void $localPublisher Publish caller-approved L1 before unlock can yield.
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

            // Cold readers share the first refill instead of serializing behind a now-hot key.
            // fresh() still takes the same lock and always replaces the value after commit.
            while (true) {
                // Another worker may have filled the caller's shared-memory L1 while
                // this reader waited. Reuse it before another Redis lock/read round;
                // a missing, invalid or unavailable L1 retains the original protocol.
                if (($local = $this->readLocal($identity, $localReader)) !== null) {
                    $outcome = 'local_cache_hit';
                    return $local;
                }
                $observation = $this->acquireWithObservation($store, $lock, $identity, $seconds);
                if ($observation !== null ? $observation['owned'] : $lock->get()) {
                    break;
                }
                $trace?->lockMiss();
                // The lock request can yield while its owner publishes L1.
                // Recheck before paying for another shared-cache round trip.
                if (($local = $this->readLocal($identity, $localReader)) !== null) {
                    $outcome = 'local_cache_hit';
                    return $local;
                }
                $cached = $observation !== null && $observation['observed']
                    ? $observation['payload']
                    : $this->validPayload($identity, $store->get($identity->redisKey()));
                if ($cached !== null) {
                    $outcome = 'contended_cache_hit';
                    return $cached;
                }
                // A shared miss may have been observed before the owner filled
                // L1 during that request. Do not sleep behind an available value.
                if (($local = $this->readLocal($identity, $localReader)) !== null) {
                    $outcome = 'local_cache_hit';
                    return $local;
                }
                if (hrtime(true) + 250_000_000 >= $deadline) {
                    throw new LockTimeoutException();
                }
                $sleepStarted = $trace?->begin();
                try {
                    // A short refill must not stall a realtime Task for 250 ms.
                    // Back off to the previous ceiling so a slow source keeps bounded polling cost.
                    Sleep::usleep($pollMicroseconds);
                    $pollMicroseconds = min(250_000, $pollMicroseconds * 2);
                } finally {
                    $trace?->slept($sleepStarted);
                }
            }

            try {
                $cached = $observation !== null && $observation['observed']
                    ? $observation['payload']
                    : $this->validPayload($identity, $store->get($identity->redisKey()));
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
                    // The loaded database value remains correct when Redis writes fail.
                }

                $outcome = 'loader';
                // Followers can use the completed value while release waits on Redis.
                // The caller must not republish it after a concurrent invalidation.
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
                    // Continue to the source only after the final shared-cache recheck fails.
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
                // An unavailable L1 never changes the value or retries the source.
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
                        // The loaded database value remains correct when Redis writes fail.
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
            // PostgreSQL remains the source of truth when the shared cache is unavailable.
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

/** Reuse Laravel's connection-aware codec; never maintain a parallel decoder. */
final class RuntimeConfigPairedRedisDecoder extends RedisStore
{
    public static function decode(RedisStore $store, mixed $raw, PhpRedisConnection $connection): mixed
    {
        return $raw === null || $raw === false ? null : $store->connectionAwareUnserialize($raw, $connection);
    }
}
