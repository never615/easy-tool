<?php

namespace Mallto\Tool\Domain\Queue;

use Illuminate\Queue\Jobs\RedisJob;

/** 保留 Horizon 的预留和事件，仅缓存返回任务的 JSON 视图。 */
class PayloadCachingHorizonRedisQueue extends BoundedHorizonRedisQueue
{
    protected bool $payloadCacheEnabled = false;

    public function cachePayloads(bool $enabled): self
    {
        $this->payloadCacheEnabled = $enabled;
        return $this;
    }

    public function pop($queue = null, $index = 0)
    {
        $job = parent::pop($queue, $index);
        if (!$this->payloadCacheEnabled
            || !$job instanceof RedisJob || get_class($job) !== RedisJob::class) {
            return $job;
        }

        // 父类 pop 已完成且仅完成一次预留，并发出 JobReserved。
        // 同时保留原始就绪 JSON 和准确的预留 JSON，使尝试次数、
        // 释放、ACK、失败记录和 Horizon 事件保留各自身份。
        return new CachedPayloadRedisJob($job->getContainer(), $this,
            $job->getRawBody(), $job->getReservedJob(),
            $job->getConnectionName(), $job->getQueue());
    }
}
