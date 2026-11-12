<?php

namespace Mallto\Tool\Domain\Queue;

use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Laravel\Horizon\JobPayload;
use Laravel\Horizon\Repositories\RedisJobRepository;
use RuntimeException;
use Throwable;

/** Preserve Horizon retention while bounding each standalone Redis trim command. */
class ChunkedHorizonJobRepository extends RedisJobRepository
{
    public const BATCH_SIZE = 256;

    private const REMOVE_EXPIRED_BATCH = <<<'LUA'
local members = redis.call('ZRANGEBYSCORE', KEYS[1], ARGV[1], '+inf', 'LIMIT', 0, ARGV[2])
if #members == 0 then return 0 end
return redis.call('ZREM', KEYS[1], unpack(members))
LUA;

    /** Admission completes before metadata: fill identity without rolling state backwards. */
    public function pushed($connection, $queue, JobPayload $payload)
    {
        if (empty($payload->decoded['bounded_high'])) return parent::pushed($connection, $queue, $payload);
        $time = str_replace(',', '.', microtime(true));
        HighQueueRedisConnection::get($this->redis, 'horizon')->eval(<<<'LUA'
local status = redis.call('HGET', KEYS[1], 'status')
local fields = {'id', 'connection', 'queue', 'name', 'payload', 'created_at', 'updated_at'}
for i, field in ipairs(fields) do redis.call('HSETNX', KEYS[1], field, ARGV[i]) end
redis.call('ZADD', KEYS[2], 'NX', ARGV[8], ARGV[1])
if not status then
    redis.call('HSET', KEYS[1], 'status', 'pending')
    redis.call('EXPIREAT', KEYS[1], ARGV[9])
end
if not status or status == 'pending' or status == 'reserved' then
    if redis.call('TTL', KEYS[1]) < 0 then redis.call('EXPIREAT', KEYS[1], ARGV[9]) end
    redis.call('ZADD', KEYS[3], 'NX', ARGV[8], ARGV[1])
end
return 1
LUA, 3, $payload->id(), 'recent_jobs', 'pending_jobs',
            $payload->id(), $connection, $queue, $payload->displayName(), $payload->value, $time, $time,
            -1 * CarbonImmutable::now()->getTimestamp(), CarbonImmutable::now()->addMinutes($this->pendingJobExpires)->getTimestamp());
    }

    public function trimRecentJobs()
    {
        if (!$this->supportsChunkedTrim()) {
            return parent::trimRecentJobs();
        }

        // Compute all boundaries before issuing commands, as the original
        // pipeline does. Negative scores sort older Jobs toward +infinity.
        $cutoffs = [];
        foreach (['recent_jobs' => $this->recentJobExpires,
            'recent_failed_jobs' => $this->recentFailedJobExpires,
            'pending_jobs' => $this->pendingJobExpires,
            'completed_jobs' => $this->completedJobExpires,
            'silenced_jobs' => $this->completedJobExpires] as $key => $minutes) {
            $cutoffs[$key] = CarbonImmutable::now()->subMinutes($minutes)->getTimestamp() * -1;
        }
        $firstError = null;
        foreach ($cutoffs as $key => $cutoff) {
            try {
                $this->trimKey($key, $cutoff);
            } catch (Throwable $error) {
                // The parent's pipeline also sends later keys when one key is
                // invalid. Do not starve healthy sets behind a bad first key.
                $firstError ??= $error;
            }
        }
        if ($firstError !== null) {
            throw $firstError;
        }
    }

    public function trimFailedJobs()
    {
        if (!$this->supportsChunkedTrim()) {
            return parent::trimFailedJobs();
        }
        $this->trimKey('failed_jobs', CarbonImmutable::now()->subMinutes($this->failedJobExpires)->getTimestamp() * -1);
    }

    public function trimMonitoredJobs()
    {
        if (!$this->supportsChunkedTrim()) {
            return parent::trimMonitoredJobs();
        }
        $this->trimKey('monitored_jobs', CarbonImmutable::now()->subMinutes($this->monitoredJobExpires)->getTimestamp() * -1);
    }

    protected function supportsChunkedTrim(): bool
    {
        $connection = $this->connection();
        // Cluster uses a different pipeline/transaction contract in Horizon.
        // Keep the original implementation for all unverified clients.
        return $connection instanceof PhpRedisConnection && $connection->client() instanceof \Redis;
    }

    private function trimKey(string $key, int $cutoff): void
    {
        // A separate request per bounded block lets Redis serve other clients
        // between blocks. Never put this whole loop in Lua, MULTI or pipeline.
        // The cutoff is fixed and offset stays zero as members are removed.
        while ($this->removeExpiredBatch($key, $cutoff) !== 0) {
        }
    }

    protected function removeExpiredBatch(string $key, int $cutoff): int
    {
        $removed = $this->connection()->eval(self::REMOVE_EXPIRED_BATCH, 1, $key, $cutoff, self::BATCH_SIZE);
        if (!is_int($removed) || $removed < 0 || $removed > self::BATCH_SIZE) {
            // No broad-command fallback or ambiguous-response replay. The next
            // ordinary trim retries only members still expired at its cutoff.
            throw new RuntimeException('Horizon bounded trim was not acknowledged.');
        }
        return $removed;
    }
}
