<?php

namespace Mallto\Tool\Domain\Queue;

use Laravel\Horizon\Repositories\RedisTagRepository;

/** Preserve live monitoring for tagged jobs without querying an empty intersection. */
class EmptyAwareHorizonTagRepository extends RedisTagRepository
{
    public function monitored(array $tags)
    {
        // No tag can be monitored when the job has none. Do not cache nonempty
        // intersections: dashboard monitoring changes must remain immediately visible.
        return $tags === [] ? [] : parent::monitored($tags);
    }
}
