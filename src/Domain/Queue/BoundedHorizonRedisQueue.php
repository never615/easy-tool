<?php

namespace Mallto\Tool\Domain\Queue;

use Illuminate\Foundation\Application;
use Laravel\Horizon\Events\JobPending;
use Laravel\Horizon\Events\JobPushed;
use Laravel\Horizon\JobPayload;
use Laravel\Horizon\RedisQueue;
use Throwable;

/** Cross-producer admission; native queue state is the only capacity ledger. */
class BoundedHorizonRedisQueue extends RedisQueue
{
    private float $blockedUntil = 0;

    private const ADMIT = <<<'LUA'
local count = redis.call('LLEN', KEYS[1]) + redis.call('ZCARD', KEYS[2]) + redis.call('ZCARD', KEYS[3])
if count >= tonumber(ARGV[1]) then return 0 end
-- Validate notify before writes: Lua errors do not roll back earlier writes.
local notifyType = redis.call('TYPE', KEYS[4]).ok
if notifyType ~= 'none' and notifyType ~= 'list' then return redis.error_reply('invalid queue notify type') end
if ARGV[3] == 'ready' then
    redis.call('RPUSH', KEYS[1], ARGV[2])
    redis.call('RPUSH', KEYS[4], 1)
else
    redis.call('ZADD', KEYS[2], ARGV[3], ARGV[2])
end
return 1
LUA;

    protected function bounded($queue): bool
    {
        return filter_var(config('queue.high_backlog.enabled', true), FILTER_VALIDATE_BOOLEAN)
            && $this->getQueue($queue) === 'queues:high';
    }

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        if (!$this->bounded($queue)) return parent::pushRaw($payload, $queue, $options);
        $payload = (new JobPayload($payload))->prepare($this->lastPushed)->set(['bounded_high' => true]);
        if ($this->container?->bound('queue.high_retry_context')
            && $this->container->make('queue.high_retry_context') === true) {
            $id = (string) \Illuminate\Support\Str::uuid();
            $payload->set(['retry_of' => $payload->id(), 'id' => $id, 'uuid' => $id]);
        }
        $result = $this->admit($payload, $queue, 'ready');
        if ($result === false && ($payload->isRetry() || $this->container?->bound('queue.high_retry_context')
            && $this->container->make('queue.high_retry_context') === true)) {
            throw new HighQueueAdmissionRejected('High queue is full or unavailable; keep the failed job and retry later.');
        }
        return $result;
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        if (!$this->bounded($queue)) return parent::later($delay, $job, $data, $queue);
        $args = version_compare(Application::VERSION, '12.11.0', '>=')
            ? [$job, $queue, $data, $delay] : [$job, $queue, $data];
        $payload = (new JobPayload($this->createPayload(...$args)))->prepare($job)->set(['bounded_high' => true]);
        return $this->enqueueUsing($job, $payload->value, $queue, $delay,
            fn ($value, $name, $wait) => $this->admit(new JobPayload($value), $name, $this->availableAt($wait)));
    }

    public function bulk($jobs, $data = '', $queue = null)
    {
        if (!$this->bounded($queue)) return parent::bulk($jobs, $data, $queue);
        // Parent wraps pushes in MULTI/pipeline, hiding individual admission responses.
        // Each item instead has an acknowledged atomic admission; partial acceptance is intentional.
        foreach ((array) $jobs as $job) {
            if (isset($job->delay)) $this->later($job->delay, $job, $data, $queue);
            else $this->push($job, $data, $queue);
        }
    }

    protected function raiseJobQueuedEvent($queue, $jobId, $job, $payload, $delay)
    {
        if ($jobId === false && $this->bounded($queue)) return;
        parent::raiseJobQueuedEvent($queue, $jobId, $job, $payload, $delay);
    }

    private function admit(JobPayload $payload, $queue, $availableAt)
    {
        if (microtime(true) < $this->blockedUntil) return false;
        $limit = max(1, (int) config('queue.high_backlog.max_jobs', 10000));
        try {
            $key = $this->getQueue($queue);
            $result = HighQueueRedisConnection::get($this->redis, $this->connection)->eval(self::ADMIT, 4,
                $key, $key.':delayed', $key.':reserved', $key.':notify',
                $limit, $payload->value, $availableAt);
            if ($result !== 1) {
                HighQueueAdmissionLog::write($this->redis, $result === 0 ? 'capacity' : 'unacknowledged', $payload, $limit);
                return false;
            }
        } catch (Throwable $error) {
            HighQueueRedisConnection::forget($this->redis, $this->connection);
            $this->blockedUntil = microtime(true) + 2;
            // Never retry an ambiguous write or fall back to synchronous business execution.
            HighQueueAdmissionLog::write($this->redis, 'redis_error', $payload, $limit, $error);
            return false;
        }
        $previousMetadataContext = $this->container?->bound('queue.high_metadata_context')
            ? $this->container->make('queue.high_metadata_context') : false;
        $this->container?->instance('queue.high_metadata_context', true);
        try {
            // Rejected work must never be stored in Horizon. ChunkedHorizonJobRepository
            // guards this late pending update against an already-reserved/completed job.
            $this->event($key, new JobPending($payload->value));
            $this->event($key, new JobPushed($payload->value));
        } catch (Throwable $error) {
            HighQueueRedisConnection::forget($this->redis, 'horizon');
            // Queue acceptance already happened. Metadata failure must not cause a duplicate retry.
            HighQueueAdmissionLog::write($this->redis, 'horizon_metadata', $payload, $limit, $error);
        } finally {
            $this->container?->instance('queue.high_metadata_context', $previousMetadataContext);
        }
        return $payload->id();
    }
}
