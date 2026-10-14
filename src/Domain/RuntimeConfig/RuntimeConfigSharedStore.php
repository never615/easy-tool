<?php

namespace Mallto\Tool\Domain\RuntimeConfig;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;
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
     * @param callable(): array{found: bool, value: mixed} $loader
     * @return array{identity: string, found: bool, value: mixed}
     */
    public function remember(RuntimeConfigIdentity $identity, callable $loader): array
    {
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
            while (!$lock->get()) {
                $cached = $this->validPayload($identity, $store->get($identity->redisKey()));
                if ($cached !== null) {
                    return $cached;
                }
                if (hrtime(true) + 250_000_000 >= $deadline) {
                    throw new LockTimeoutException();
                }
                // A short refill must not stall an entire realtime Task for 250 ms.
                // Back off to the old ceiling so a slow source keeps bounded polling cost.
                Sleep::usleep($pollMicroseconds);
                $pollMicroseconds = min(250_000, $pollMicroseconds * 2);
            }

            try {
                $cached = $this->validPayload($identity, $store->get($identity->redisKey()));
                if ($cached !== null) {
                    return $cached;
                }

                $loaderStarted = true;
                $loadedPayload = $this->payload($identity, $loader());
                try {
                    $this->putWithStore($store, $identity, $loadedPayload);
                } catch (Throwable) {
                    // The loaded database value remains correct when Redis writes fail.
                }

                return $loadedPayload;
            } finally {
                $lock->release();
            }
        } catch (Throwable $exception) {
            if ($loaderStarted) {
                if ($loadedPayload !== null) {
                    return $loadedPayload;
                }

                throw $exception;
            }

            if ($store !== null) {
                try {
                    $cached = $this->validPayload($identity, $store->get($identity->redisKey()));
                    if ($cached !== null) {
                        return $cached;
                    }
                } catch (Throwable) {
                    // Continue to the source only after the final shared-cache recheck fails.
                }
            }

            $payload = $this->payload($identity, $loader());
            $this->put($identity, $payload);

            return $payload;
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
