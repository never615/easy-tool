<?php

namespace Mallto\Tool\Domain\Queue;

use Laravel\Horizon\Repositories\RedisTagRepository;

/** 保留带标签任务的实时监控，避免查询空交集。 */
class EmptyAwareHorizonTagRepository extends RedisTagRepository
{
    protected function connection()
    {
        if (app()->bound('queue.high_metadata_context') && app('queue.high_metadata_context') === true) {
            return HighQueueRedisConnection::get($this->redis, 'horizon');
        }
        return parent::connection();
    }

    public function monitored(array $tags)
    {
        // 任务没有标签时，没有可监控的标签。不要缓存非空
        // 交集：仪表盘监控变更必须立即可见。
        return $tags === [] ? [] : parent::monitored($tags);
    }
}
