<?php

namespace Mallto\Tool\Domain\Queue;

use Illuminate\Foundation\Application;
use Laravel\Horizon\Events\JobPending;
use Laravel\Horizon\Events\JobPushed;
use Laravel\Horizon\Events\JobReleased;
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

    private function queueName($queue): string
    {
        return substr($this->getQueue($queue), strlen('queues:'));
    }

    private function limit($queue, string $setting, int $default): int
    {
        $name = $this->queueName($queue);
        $fallback = config('queue.backlog.'.$setting, $default);
        if ($name === 'high' && $setting === 'max_jobs') {
            $fallback = config('queue.high_backlog.max_jobs', $fallback);
        }
        // Index by literal queue name: dots and Redis hash tags are valid names.
        $overrides = (array) config('queue.backlog.queues', []);
        return max(1, (int) ($overrides[$name][$setting] ?? $fallback));
    }

    private function admissionResult($result, JobPayload $payload, $queue)
    {
        if ($result !== false) return $result;
        $manualRetry = $payload->isRetry() || ($this->container?->bound('queue.high_retry_context')
            && $this->container->make('queue.high_retry_context') === true);
        // Preserve the existing lossy high path. Other queues must signal rejection
        // to callers (including chains/batches), never report an unqueued job as delivered.
        if ($this->queueName($queue) === 'high' && !$manualRetry) return false;
        $exception = $this->queueName($queue) === 'high'
            ? HighQueueAdmissionRejected::class : QueueAdmissionRejected::class;
        throw new $exception('Queue admission rejected (capacity, payload size or Redis unavailable); '
            .'keep the failed job if retrying.');
    }

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        if (!QueueBacklogProtection::enabled()) return parent::pushRaw($payload, $queue, $options);

        $payload = (new JobPayload($payload))->prepare($this->lastPushed)->set(['bounded_queue' => true]);
        if ($this->container?->bound('queue.high_retry_context')
            && $this->container->make('queue.high_retry_context') === true) {
            $id = (string) \Illuminate\Support\Str::uuid();
            $payload->set(['retry_of' => $payload->id(), 'id' => $id, 'uuid' => $id]);
        }
        return $this->admissionResult($this->admit($payload, $queue, 'ready'), $payload, $queue);
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        if (!QueueBacklogProtection::enabled()) return parent::later($delay, $job, $data, $queue);

        $args = version_compare(Application::VERSION, '12.11.0', '>=')
            ? [$job, $queue, $data, $delay] : [$job, $queue, $data];
        $payload = (new JobPayload($this->createPayload(...$args)))->prepare($job)->set(['bounded_queue' => true]);
        return $this->enqueueUsing($job, $payload->value, $queue, $delay,
            function ($value, $name, $wait) {
                $payload = new JobPayload($value);
                return $this->admissionResult($this->admit($payload, $name, $this->availableAt($wait)), $payload, $name);
            });
    }

    public function bulk($jobs, $data = '', $queue = null)
    {
        if (!QueueBacklogProtection::enabled()) return parent::bulk($jobs, $data, $queue);

        // Parent wraps pushes in MULTI/pipeline, hiding individual admission responses.
        // Each item instead has an acknowledged atomic admission; partial acceptance is intentional.
        foreach ((array) $jobs as $job) {
            if (isset($job->delay)) $this->later($job->delay, $job, $data, $queue);
            else $this->push($job, $data, $queue);
        }
    }

    protected function raiseJobQueuedEvent($queue, $jobId, $job, $payload, $delay)
    {
        if ($jobId === false && QueueBacklogProtection::enabled()) return;
        parent::raiseJobQueuedEvent($queue, $jobId, $job, $payload, $delay);
    }

    public function deleteAndRelease($queue, $job, $delay)
    {
        if (!QueueBacklogProtection::enabled()) return parent::deleteAndRelease($queue, $job, $delay);

        $key = $this->getQueue($queue);
        // A late worker must not recreate work already migrated from its expired
        // reservation. Only an existing reservation may move into delayed.
        $released = $this->getConnection()->eval(<<<'LUA'
local delayedType = redis.call('TYPE', KEYS[1]).ok
if delayedType ~= 'none' and delayedType ~= 'zset' then return redis.error_reply('invalid delayed queue type') end
if redis.call('ZREM', KEYS[2], ARGV[1]) == 0 then return 0 end
redis.call('ZADD', KEYS[1], ARGV[2], ARGV[1])
return 1
LUA, 2, $key.':delayed', $key.':reserved', $job->getReservedJob(), $this->availableAt($delay));
        if ($released === 1) $this->event($key, new JobReleased($job->getReservedJob(), $delay));
    }

    private function admit(JobPayload $payload, $queue, $availableAt)
    {
        if (microtime(true) < $this->blockedUntil) return false;
        $limit = $this->limit($queue, 'max_jobs', 100000);
        $name = $this->queueName($queue);
        if (strlen($payload->value) > $this->limit($queue, 'max_payload_bytes', 1048576)) {
            HighQueueAdmissionLog::write($this->redis, 'payload_size', $payload, $limit, null, $name, $this->connection);
            return false;
        }
        try {
            $key = $this->getQueue($queue);
            $result = HighQueueRedisConnection::get($this->redis, $this->connection, $name !== 'high')->eval(self::ADMIT, 4,
                $key, $key.':delayed', $key.':reserved', $key.':notify',
                $limit, $payload->value, $availableAt);
            if ($result !== 1) {
                HighQueueAdmissionLog::write($this->redis, $result === 0 ? 'capacity' : 'unacknowledged', $payload, $limit, null, $name, $this->connection);
                return false;
            }
        } catch (Throwable $error) {
            HighQueueRedisConnection::forget($this->redis, $this->connection);
            $this->blockedUntil = microtime(true) + 2;
            // Never retry an ambiguous write or fall back to synchronous business execution.
            HighQueueAdmissionLog::write($this->redis, 'redis_error', $payload, $limit, $error, $name, $this->connection);
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
            HighQueueAdmissionLog::write($this->redis, 'horizon_metadata', $payload, $limit, $error, $name, $this->connection);
        } finally {
            $this->container?->instance('queue.high_metadata_context', $previousMetadataContext);
        }
        return $payload->id();
    }
}
