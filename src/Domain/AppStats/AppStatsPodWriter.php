<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\AppStats;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * 将 Pod 快照写入一个固定 Hash，并用固定 ZSet 维护最后采样时间索引。
 *
 * 所有写入和删除都让 Hash/ZSet 位于同一 pipeline 中。清理只通过 ZSet 的时间分数查找候选项，
 * 不执行 KEYS、SCAN 或全库遍历，因此成本只与过期 Pod 数量相关。
 */
final class AppStatsPodWriter
{
    /**
     * 注入本组件运行所需的协作依赖，保持构造与业务执行解耦。
     */
    public function __construct(
        private readonly RedisFactory $redis,
        private readonly ConfigRepository $config
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function store(string $hostname, array $payload, int $sampledAt): void
    {
        $connection = $this->connection();
        $json = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        // 同一个 pipeline 保证两条固定 Key 写入连续发出，避免为每次 5 秒采样增加网络往返。
        $connection->pipeline(function ($pipe) use ($hostname, $json, $sampledAt): void {
            $pipe->hset($this->hashKey(), $hostname, $json);
            $pipe->zadd($this->lastSeenKey(), $sampledAt, $hostname);
        });
    }

    /**
     * 删除超过配置保留时间的 Pod 快照，并返回删除候选数量。
     */
    public function cleanup(int $now): int
    {
        $connection = $this->connection();
        $cutoff = $now - max(1, (int)$this->config->get('app_stats.cleanup_seconds', 300));
        $hostnames = array_values((array)$connection->zrangebyscore($this->lastSeenKey(), '-inf', $cutoff));

        if ($hostnames === []) {
            return 0;
        }

        $connection->pipeline(function ($pipe) use ($hostnames): void {
            $pipe->hdel($this->hashKey(), ...$hostnames);
            $pipe->zrem($this->lastSeenKey(), ...$hostnames);
        });

        return count($hostnames);
    }

    /**
     * 在当前 LaravelS 实例正常 reload/stop 时同时移除该 hostname 的快照和时间索引。
     */
    public function forget(string $hostname): void
    {
        $this->connection()->pipeline(function ($pipe) use ($hostname): void {
            $pipe->hdel($this->hashKey(), $hostname);
            $pipe->zrem($this->lastSeenKey(), $hostname);
        });
    }

    /**
     * 处理「connection」相关的内部协作步骤。
     */
    private function connection()
    {
        $name = $this->config->get('app_stats.redis_connection');

        return $this->redis->connection(is_string($name) && $name !== '' ? $name : null);
    }

    /**
     * 处理「hashKey」相关的内部协作步骤。
     */
    private function hashKey(): string
    {
        return (string)$this->config->get('app_stats.pods_hash_key', 'swoole_stats:pods');
    }

    /**
     * 处理「lastSeenKey」相关的内部协作步骤。
     */
    private function lastSeenKey(): string
    {
        return (string)$this->config->get('app_stats.pods_last_seen_key', 'swoole_stats:pods:last_seen');
    }
}
