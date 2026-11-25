<?php

namespace Mallto\Tool\Domain\Queue;

use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Laravel\Horizon\JobPayload;
use Laravel\Horizon\Repositories\RedisJobRepository;
use RuntimeException;
use Throwable;

/** 保留 Horizon 的保留策略，并限制每条独立 Redis 清理命令的规模。 */
class ChunkedHorizonJobRepository extends RedisJobRepository
{
    public const BATCH_SIZE = 256;

    private const REMOVE_EXPIRED_BATCH = <<<'LUA'
local members = redis.call('ZRANGEBYSCORE', KEYS[1], ARGV[1], '+inf', 'LIMIT', 0, ARGV[2])
if #members == 0 then return 0 end
return redis.call('ZREM', KEYS[1], unpack(members))
LUA;

    /** 准入先于元数据写入完成：补全身份信息时不要回退状态。 */
    public function pushed($connection, $queue, JobPayload $payload)
    {
        // 配置在事件处理期间变化时，仍补写已准入任务的元数据。
        // 旧重试载荷上的标记本身不会启用保护。
        if (!QueueBacklogProtection::enabled()
            && !(app()->bound('queue.high_metadata_context') && app('queue.high_metadata_context') === true)) {
            return parent::pushed($connection, $queue, $payload);
        }
        if (empty($payload->decoded['bounded_queue']) && empty($payload->decoded['bounded_high'])) {
            return parent::pushed($connection, $queue, $payload);
        }
        $time = str_replace(',', '.', microtime(true));
        HighQueueRedisConnection::get($this->redis, 'horizon')->eval(<<<'LUA'
local status = redis.call('HGET', KEYS[1], 'status')
local fields = {'id', 'connection', 'queue', 'name', 'payload', 'created_at', 'updated_at'}
if not status and redis.call('EXISTS', KEYS[1]) == 0 then
    redis.call('HSET', KEYS[1], 'id', ARGV[1], 'connection', ARGV[2], 'queue', ARGV[3],
        'name', ARGV[4], 'payload', ARGV[5], 'created_at', ARGV[6], 'updated_at', ARGV[7],
        'status', 'pending')
    redis.call('EXPIREAT', KEYS[1], ARGV[9])
    redis.call('ZADD', KEYS[2], 'NX', ARGV[8], ARGV[1])
    redis.call('ZADD', KEYS[3], 'NX', ARGV[8], ARGV[1])
    return 1
end
-- Worker metadata may already exist even when the status field is absent.
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

        // 与原有管道一致，在发出命令前计算所有边界。
        // 负分值使较早任务排向正无穷。
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
                // 某个键无效时，父类管道仍会发送后续键。
                // 不能让首个坏键阻塞后续健康集合。
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
        // Horizon 对集群使用不同的管道和事务合同。
        // 对所有未经验证的客户端保留原有实现。
        return $connection instanceof PhpRedisConnection && $connection->client() instanceof \Redis;
    }

    private function trimKey(string $key, int $cutoff): void
    {
        // 每个有界分块独立请求，使 Redis 能在分块之间服务其他客户端。
        // 不要将整个循环放进 Lua、MULTI 或管道。
        // 移除成员时保持截止值固定，偏移量始终为零。
        while ($this->removeExpiredBatch($key, $cutoff) !== 0) {
        }
    }

    protected function removeExpiredBatch(string $key, int $cutoff): int
    {
        $removed = $this->connection()->eval(self::REMOVE_EXPIRED_BATCH, 1, $key, $cutoff, self::BATCH_SIZE);
        if (!is_int($removed) || $removed < 0 || $removed > self::BATCH_SIZE) {
            // 不回退到宽泛命令，也不重放结果不明确的响应。下一次
            // 常规清理仅重试截止时仍已过期的成员。
            throw new RuntimeException('Horizon bounded trim was not acknowledged.');
        }
        return $removed;
    }
}
