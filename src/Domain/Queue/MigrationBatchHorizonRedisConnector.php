<?php

namespace Mallto\Tool\Domain\Queue;

use Illuminate\Support\Arr;
use Laravel\Horizon\Connectors\RedisConnector;
use Laravel\Horizon\RedisQueue;

/** 保留 Horizon 的队列和生命周期，同时传递框架迁移批次上限。 */
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
        // 保留 Horizon 原有六个参数显式传入 null 时的语义。
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
