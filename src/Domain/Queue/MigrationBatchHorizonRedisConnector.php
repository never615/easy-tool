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
        // Historical bounded registration (suspended):
        // A resident connection may outlive a control change. The bounded class
        // itself takes the native bypass before serialization/admission when off;
        // choosing the native class here would make a later enable ineffective.
        // Admission is suspended on develop. Keep its implementation for later review,
        // but instantiate native Horizon directly so no per-job guard runs.
        // return $this->createQueue($config, BoundedHorizonRedisQueue::class);
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
