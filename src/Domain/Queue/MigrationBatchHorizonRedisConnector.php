<?php

namespace Mallto\Tool\Domain\Queue;

use Illuminate\Support\Arr;
use Laravel\Horizon\Connectors\RedisConnector;
use Laravel\Horizon\RedisQueue;

/** Preserve Horizon's queue and lifecycle while forwarding the framework migration batch limit. */
class MigrationBatchHorizonRedisConnector extends RedisConnector
{
    public function connect(array $config)
    {
        return $this->createQueue($config, RedisQueue::class);
    }

    protected function createQueue(array $config, string $queueClass): RedisQueue
    {
        // Keep Horizon's explicit-null semantics for the original six parameters.
        return new $queueClass(
            $this->redis, $config['queue'],
            Arr::get($config, 'connection', $this->connection),
            Arr::get($config, 'retry_after', 60),
            Arr::get($config, 'block_for', null),
            Arr::get($config, 'after_commit', null),
            $config['migration_batch_size'] ?? -1
        );
    }
}
