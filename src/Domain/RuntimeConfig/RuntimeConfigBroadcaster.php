<?php

namespace Mallto\Tool\Domain\RuntimeConfig;

use Illuminate\Redis\RedisManager;

final class RuntimeConfigBroadcaster
{
    public function __construct(private readonly RedisManager $redis)
    {
    }

    /** @param list<RuntimeConfigIdentity> $identities */
    public function broadcast(array $identities, int $revision): void
    {
        $message = json_encode([
            'revision' => $revision,
            'identities' => array_map(
                static fn (RuntimeConfigIdentity $identity): array => $identity->broadcastPayload(),
                array_values($identities)
            ),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->connection()->publish(
            (string) config('runtime_config.channel', 'runtime_config:invalidate'),
            $message
        );
    }

    private function connection()
    {
        $store = (string) config('runtime_config.shared_store', 'redis');
        $connection = config('cache.stores.'.$store.'.connection');

        return $this->redis->connection(is_string($connection) && $connection !== '' ? $connection : null);
    }
}
