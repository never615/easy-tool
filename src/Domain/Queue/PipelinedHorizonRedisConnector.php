<?php

namespace Mallto\Tool\Domain\Queue;

use Illuminate\Contracts\Redis\Factory;

/** 按需启用的队列优化，保留 Horizon 的负载和生命周期事件。 */
class PipelinedHorizonRedisConnector extends MigrationBatchHorizonRedisConnector
{
    public function __construct(Factory $redis, $connection = null,
        private bool $migrationPipeline = true, private bool $payloadCache = false)
    {
        parent::__construct($redis, $connection);
    }

    public function connect(array $config)
    {
        // 保持已安装 Horizon 的默认值，并传递配置的迁移批次上限。
        $queueClass = $this->migrationPipeline
            ? PipelinedHorizonRedisQueue::class : PayloadCachingHorizonRedisQueue::class;
        return $this->createQueue($config, $queueClass)->cachePayloads($this->payloadCache);
    }
}
