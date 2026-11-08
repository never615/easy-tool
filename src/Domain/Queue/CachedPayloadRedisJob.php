<?php

namespace Mallto\Tool\Domain\Queue;

use Illuminate\Queue\Jobs\RedisJob;

/** Reuse the immutable ready payload already decoded by RedisJob's constructor. */
class CachedPayloadRedisJob extends RedisJob
{
    public function payload()
    {
        // During the parent constructor decoded is not populated yet. Invalid
        // JSON keeps the parent's null result and error behavior as well.
        return $this->decoded ?? parent::payload();
    }
}
