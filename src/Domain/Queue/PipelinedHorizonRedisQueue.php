<?php

namespace Mallto\Tool\Domain\Queue;

use Illuminate\Queue\LuaScripts;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Laravel\Horizon\Events\JobsMigrated;
use Laravel\Horizon\RedisQueue;
use RuntimeException;

/** Batch migration reads/writes only; reservation, blocking, ACK and release stay in Horizon. */
class PipelinedHorizonRedisQueue extends RedisQueue
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

        // Use the installed framework's exact scripts and batch limit, not a new merged Lua.
        // Never include pop: a migration error must not reserve a Job that cannot be returned.
        $delayedTime = $this->currentTime();
        $reservedTime = $this->currentTime();
        $responses = $connection->pipeline(function ($pipe) use ($queue, $delayedTime, $reservedTime): void {
            // PhpRedis pipeline exposes the native client, whose EVAL signature differs from Laravel's.
            $pipe->eval(LuaScripts::migrateExpiredJobs(), [
                $queue . ':delayed', $queue, $queue . ':notify', $delayedTime, $this->migrationBatchSize,
            ], 3);
            $pipe->eval(LuaScripts::migrateExpiredJobs(), [
                $queue . ':reserved', $queue, $queue . ':notify', $reservedTime, $this->migrationBatchSize,
            ], 3);
        });

        // A partial pipeline may have migrated Jobs already. Leave them ready, do not replay
        // commands or pop on failure; the next normal attempt can consume them safely.
        if (!is_array($responses) || count($responses) !== 2
            || !is_array($responses[0]) || !is_array($responses[1])) {
            throw new RuntimeException('Queue migration pipeline failed; no Job was reserved');
        }
        foreach ($responses as $jobs) {
            $this->event($queue, new JobsMigrated($jobs));
        }
    }
}
