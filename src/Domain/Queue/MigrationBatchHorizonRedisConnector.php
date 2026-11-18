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
        return $this->createQueue($config, BoundedHorizonRedisQueue::class);
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
