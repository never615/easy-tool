<?php

namespace Mallto\Tool\Domain\Queue;

use Illuminate\Contracts\Redis\Factory;

/** Opt-in queue optimizations retaining Horizon's payload and lifecycle events. */
class PipelinedHorizonRedisConnector extends MigrationBatchHorizonRedisConnector
{
    public function __construct(Factory $redis, $connection = null,
        private bool $migrationPipeline = true, private bool $payloadCache = false)
    {
        parent::__construct($redis, $connection);
    }

    public function connect(array $config)
    {
        // Mirror the installed Horizon defaults and forward the configured migration batch limit.
        $queueClass = $this->migrationPipeline
            ? PipelinedHorizonRedisQueue::class : PayloadCachingHorizonRedisQueue::class;
        return $this->createQueue($config, $queueClass)->cachePayloads($this->payloadCache);
    }
}
