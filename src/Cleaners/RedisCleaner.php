<?php

namespace Mallto\Tool\Cleaners;

use Hhxsv5\LaravelS\Illuminate\Cleaners\BaseCleaner;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Log;

/**
 * LaravelS 请求级 Redis 连接健康检查清理器。
 *
 * 问题背景：
 * LaravelS (Swoole) 是常驻内存进程，phpredis 使用 persistent 连接时
 * 连接 fd 在 worker 进程级缓存。当 Redis 重启后，已缓存的连接句柄
 * 变为无效状态，但 phpredis 和 Laravel RedisManager 都不会自动检测
 * 和重建连接，导致后续所有 Redis 操作失败。
 *
 * 解决方式：
 * 每次请求结束后，对所有已建立的 Redis 连接执行 PING 健康检查。
 * 如果 PING 失败（连接已断），调用 RedisManager::purge() 移除该连接缓存，
 * 下次请求使用 Redis 时会自动建立新连接。
 *
 * 性能影响：
 * - 正常情况：每个 PING 耗时 < 0.1ms（本地 Redis），几乎无开销
 * - 连接异常：仅断开坏连接，下次请求自动重连
 *
 * 配置：
 * 通过 REDIS_CLEANER_ENABLED 环境变量控制（默认关闭），
 * 仅在容器环境（OpenShift/K8S）中 Redis 重启可能导致半开连接时才需要开启。
 */
class RedisCleaner extends BaseCleaner
{
    public function clean()
    {
        // 通过 REDIS_CLEANER_ENABLED 环境变量控制是否启用（默认关闭）
        // 仅在容器环境（OpenShift/K8S）中 Redis 重启可能导致半开连接时才需要开启
        if (!config('database.redis_cleaner_enabled', false)) {
            return;
        }

        try {
            /** @var RedisManager $manager */
            $manager = $this->currentApp->make('redis');
            $connections = $manager->connections();

            if (empty($connections)) {
                return;
            }

            foreach ($connections as $name => $connection) {
                try {
                    // PING 检测连接是否存活
                    $connection->ping();
                } catch (\Throwable $e) {
                    // 连接已断开，清除缓存，下次请求自动重新建立连接
                    Log::warning("[RedisCleaner] Redis 连接 [{$name}] 已断开，已清除连接缓存等待重连: " . $e->getMessage());
                    $manager->purge($name);
                }
            }
        } catch (\Throwable $e) {
            // RedisManager 本身不可用时不阻塞请求
            Log::error("[RedisCleaner] 清理异常: " . $e->getMessage());
        }
    }
}
