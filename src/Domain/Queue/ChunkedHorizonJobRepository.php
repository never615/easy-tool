<?php

namespace Mallto\Tool\Domain\Queue;

use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\PhpRedisConnection;
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
