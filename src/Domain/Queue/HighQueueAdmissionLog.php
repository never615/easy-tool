<?php

namespace Mallto\Tool\Domain\Queue;

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\JobPayload;
use Throwable;

/** 同一队列和原因的滚动错误窗口由所有生产者共享。 */
final class HighQueueAdmissionLog
{
    private static ?\WeakMap $attempts = null;

    public static function write(Factory $redis, string $reason, JobPayload $payload, int $limit,
        ?Throwable $error = null, string $queue = 'high', ?string $redisConnection = null): void
    {
        self::$attempts ??= new \WeakMap();
        // Horizon 元数据异常发生在队列接纳之后，不能压住真正的拒收证据。
        $window = hash('sha256', ($redisConnection ?? 'default').':'.$queue.':'.$reason);
        $attempts = self::$attempts[$redis] ?? [];
        $attempt = $attempts[$window] ?? [0, 0];
        if ($attempt[0] === getmypid() && microtime(true) < $attempt[1]) return;
        // 即使生产者使用动态名称，也要限制进程本地记录的规模。
        if (count($attempts) >= 256) $attempts = [];
        $attempts[$window] = [getmypid(), microtime(true) + 60];
        self::$attempts[$redis] = $attempts;
        $logConnection = config('queue.backlog.log_redis_connection',
            config('queue.high_backlog.log_redis_connection', 'default'));
        try {
            // 拆分部署时，将此控制键放在队列 Redis 之外。
            $connection = HighQueueRedisConnection::get($redis, $logConnection);
            $claimed = $connection->set('queue:admission:error-window:'.$window, 1, 'EX', 3600, 'NX');
            if (!$claimed) return;
            Log::error('Queue admission protection triggered', [
                'queue' => $queue, 'redis_connection' => $redisConnection ?? 'default',
                'reason' => $reason, 'limit' => $limit,
                'job' => $payload->displayName(), 'job_id' => $payload->id(),
                // 不要记录负载、序列化参数、凭据或任意错误消息。
                'error_class' => $error ? get_class($error) : null,
            ]);
        } catch (Throwable) {
            HighQueueRedisConnection::forget($redis, $logConnection);
            // 不使用进程本地降级，否则 Redis 不可用时，各 Pod 会产生大量日志。
            // 还必须独立于此日志监控 Redis 可用性。
        }
    }
}
