<?php

namespace Mallto\Tool\Domain\Queue;

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\JobPayload;
use Throwable;

/** A single rolling error window shared by all producers, jobs and rejection reasons. */
final class HighQueueAdmissionLog
{
    private static ?\WeakMap $attempts = null;

    public static function write(Factory $redis, string $reason, JobPayload $payload, int $limit, ?Throwable $error = null): void
    {
        self::$attempts ??= new \WeakMap();
        $attempt = self::$attempts[$redis] ?? [0, 0];
        if ($attempt[0] === getmypid() && microtime(true) < $attempt[1]) return;
        self::$attempts[$redis] = [getmypid(), microtime(true) + 60];
        try {
            // Keep this control key outside the queue Redis when using the split deployment.
            $connection = HighQueueRedisConnection::get($redis, config('queue.high_backlog.log_redis_connection', 'default'));
            $claimed = $connection->set('queue:high:admission:error-window', 1, 'EX', 3600, 'NX');
            if (!$claimed) return;
            Log::error('High queue admission protection triggered', [
                'queue' => 'high', 'reason' => $reason, 'limit' => $limit,
                'job' => $payload->displayName(), 'job_id' => $payload->id(),
                // Do not log payloads, serialized arguments, credentials or arbitrary error messages.
                'error_class' => $error ? get_class($error) : null,
            ]);
        } catch (Throwable) {
            HighQueueRedisConnection::forget($redis, config('queue.high_backlog.log_redis_connection', 'default'));
            // No per-process fallback: it would flood logs across Pods when Redis is unavailable.
            // Redis availability must also be monitored independently of this log.
        }
    }
}
