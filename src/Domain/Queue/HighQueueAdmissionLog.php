<?php

namespace Mallto\Tool\Domain\Queue;

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\JobPayload;
use Throwable;

/** Per-queue rolling error windows shared by all producers, jobs and rejection reasons. */
final class HighQueueAdmissionLog
{
    private static ?\WeakMap $attempts = null;

    public static function write(Factory $redis, string $reason, JobPayload $payload, int $limit,
        ?Throwable $error = null, string $queue = 'high', ?string $redisConnection = null): void
    {
        self::$attempts ??= new \WeakMap();
        $window = hash('sha256', ($redisConnection ?? 'default').':'.$queue);
        $attempts = self::$attempts[$redis] ?? [];
        $attempt = $attempts[$window] ?? [0, 0];
        if ($attempt[0] === getmypid() && microtime(true) < $attempt[1]) return;
        // Bound process-local bookkeeping even when producers use dynamic names.
        if (count($attempts) >= 256) $attempts = [];
        $attempts[$window] = [getmypid(), microtime(true) + 60];
        self::$attempts[$redis] = $attempts;
        $logConnection = config('queue.backlog.log_redis_connection',
            config('queue.high_backlog.log_redis_connection', 'default'));
        try {
            // Keep this control key outside the queue Redis when using the split deployment.
            $connection = HighQueueRedisConnection::get($redis, $logConnection);
            $claimed = $connection->set('queue:admission:error-window:'.$window, 1, 'EX', 3600, 'NX');
            if (!$claimed) return;
            Log::error('Queue admission protection triggered', [
                'queue' => $queue, 'redis_connection' => $redisConnection ?? 'default',
                'reason' => $reason, 'limit' => $limit,
                'job' => $payload->displayName(), 'job_id' => $payload->id(),
                // Do not log payloads, serialized arguments, credentials or arbitrary error messages.
                'error_class' => $error ? get_class($error) : null,
            ]);
        } catch (Throwable) {
            HighQueueRedisConnection::forget($redis, $logConnection);
            // No per-process fallback: it would flood logs across Pods when Redis is unavailable.
            // Redis availability must also be monitored independently of this log.
        }
    }
}
