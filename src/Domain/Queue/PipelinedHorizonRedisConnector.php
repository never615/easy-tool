<?php

namespace Mallto\Tool\Domain\Queue;

use Laravel\Horizon\Connectors\RedisConnector;
use Illuminate\Support\Arr;
use Illuminate\Contracts\Redis\Factory;

/** Opt-in queue optimizations retaining Horizon's payload and lifecycle events. */
class PipelinedHorizonRedisConnector extends RedisConnector
{
    public function __construct(Factory $redis, $connection = null,
        private bool $migrationPipeline = true, private bool $payloadCache = false)
    {
        parent::__construct($redis, $connection);
    }

    public function connect(array $config)
    {
        // Mirror the installed Horizon connector's constructor defaults exactly.
        $queueClass = $this->migrationPipeline
            ? PipelinedHorizonRedisQueue::class : PayloadCachingHorizonRedisQueue::class;
        return (new $queueClass(
            $this->redis, $config['queue'],
            Arr::get($config, 'connection', $this->connection),
            Arr::get($config, 'retry_after', 60),
            Arr::get($config, 'block_for', null),
            Arr::get($config, 'after_commit', null)
        ))->cachePayloads($this->payloadCache);
    }
}
