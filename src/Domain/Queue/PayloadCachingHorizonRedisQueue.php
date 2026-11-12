<?php

namespace Mallto\Tool\Domain\Queue;

use Illuminate\Queue\Jobs\RedisJob;

/** Keep Horizon's reservation/events; cache only the returned Job's JSON view. */
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

        // Parent pop already reserved exactly once and emitted JobReserved.
        // Preserve both original ready JSON and exact reserved JSON: attempts,
        // release, ACK, failure records and Horizon events keep their identity.
        return new CachedPayloadRedisJob($job->getContainer(), $this,
            $job->getRawBody(), $job->getReservedJob(),
            $job->getConnectionName(), $job->getQueue());
    }
}
