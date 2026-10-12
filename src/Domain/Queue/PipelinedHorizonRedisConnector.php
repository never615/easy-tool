<?php

namespace Mallto\Tool\Domain\Queue;

use Laravel\Horizon\Connectors\RedisConnector;
use Illuminate\Support\Arr;

/** Opt-in connector retaining Horizon's payload and lifecycle events. */
class PipelinedHorizonRedisConnector extends RedisConnector
{
    public function connect(array $config)
    {
        // Mirror the installed Horizon connector's constructor defaults exactly.
        return new PipelinedHorizonRedisQueue(
            $this->redis, $config['queue'],
            Arr::get($config, 'connection', $this->connection),
            Arr::get($config, 'retry_after', 60),
            Arr::get($config, 'block_for', null),
            Arr::get($config, 'after_commit', null)
        );
    }
}
