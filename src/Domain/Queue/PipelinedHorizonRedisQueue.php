<?php

namespace Mallto\Tool\Domain\Queue;

use Illuminate\Queue\LuaScripts;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Laravel\Horizon\Events\JobsMigrated;
use RuntimeException;

/** 仅批量执行迁移读写；预留、阻塞、ACK 及释放仍由 Horizon 负责。 */
class PipelinedHorizonRedisQueue extends PayloadCachingHorizonRedisQueue
{
    protected function migrate($queue)
    {
        $connection = $this->getConnection();
        if ($this->retryAfter === null || $this->blockFor !== null
            || !$connection instanceof PhpRedisConnection
            || !$connection->client() instanceof \Redis) {
            parent::migrate($queue);
            return;
        }

        // 使用已安装框架原有的脚本及批次上限，不新建合并后的 Lua。
        // 不要包含 pop：迁移错误不能导致预留一个无法返回的任务。
        $delayedTime = $this->currentTime();
        $reservedTime = $this->currentTime();
        $responses = $connection->pipeline(function ($pipe) use ($queue, $delayedTime, $reservedTime): void {
            // PhpRedis 管道暴露原生客户端，其 EVAL 签名与 Laravel 不同。
            $pipe->eval(LuaScripts::migrateExpiredJobs(), [
                $queue . ':delayed', $queue, $queue . ':notify', $delayedTime, $this->migrationBatchSize,
            ], 3);
            $pipe->eval(LuaScripts::migrateExpiredJobs(), [
                $queue . ':reserved', $queue, $queue . ':notify', $reservedTime, $this->migrationBatchSize,
            ], 3);
        });

        // 部分执行的管道可能已经迁移了任务。保留其就绪状态，不要重放
        // 失败时不要重放命令，也不要执行 pop；下一次正常尝试可安全消费这些任务。
        if (!is_array($responses) || count($responses) !== 2
            || !is_array($responses[0]) || !is_array($responses[1])) {
            $connection->disconnect();
            throw new RuntimeException('Queue migration pipeline failed; no Job was reserved');
        }
        foreach ($responses as $jobs) {
            $this->event($queue, new JobsMigrated($jobs));
        }
    }
}
