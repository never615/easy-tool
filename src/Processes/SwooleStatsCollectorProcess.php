<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Processes;

use Hhxsv5\LaravelS\Swoole\Process\CustomProcessInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Swoole\Http\Server;
use Swoole\Process;

/**
 * Swoole 状态采集进程
 *
 * 每个 pod 中各运行一个实例。检查 Redis 中的采集开关（由 SwooleStatsController 触发），
 * 开关打开时每 2 秒将本 pod 的 Swoole stats 写入 Redis Hash，供 Controller 读取聚合。
 *
 * Redis 键：
 *   swoole_stats:collecting  — String，TTL = 采集持续时间，存在即表示"正在采集"
 *   swoole_stats:pods        — Hash，Field = hostname，Value = JSON({hostname, stats, updated_at})
 *
 * User: never615 <never615.com>
 * Date: 2026/3/22
 */
class SwooleStatsCollectorProcess implements CustomProcessInterface
{
    /**
     * 采集开关 Redis 键
     */
    public const COLLECTING_KEY = 'swoole_stats:collecting';

    /**
     * 各 pod 指标存储 Redis Hash 键
     */
    public const PODS_HASH_KEY = 'swoole_stats:pods';

    /**
     * 轮询间隔（秒）：检查采集开关 + 写入指标
     */
    protected const POLL_INTERVAL = 2;

    /**
     * pod 数据过期时间（秒）：超过此时间未更新则视为下线
     */
    public const POD_DATA_TTL = 15;

    /**
     * @param Server  $swoole
     * @param Process $process
     */
    public static function callback(Server $swoole, Process $process)
    {
        $hostname = gethostname();
        Log::info("[SwooleStatsCollector] 进程启动，hostname: {$hostname}");

        while (true) {
            try {
                // 检查采集开关
                $collecting = Redis::exists(self::COLLECTING_KEY);

                if ($collecting) {
                    // 采集本 pod 的 Swoole 指标并写入 Redis Hash
                    $stats = $swoole->stats();

                    $data = json_encode([
                        'hostname'   => $hostname,
                        'updated_at' => time(),
                        'stats'      => [
                            'swoole_cpu_num'        => swoole_cpu_num(),
                            'worker_num'            => $stats['worker_num'] ?? 0,
                            'idle_worker_num'       => $stats['idle_worker_num'] ?? 0,
                            'worker_num_use'        => ($stats['worker_num'] - ($stats['idle_worker_num'] ?? 0))
                                . '/' . $stats['worker_num'],
                            'task_worker_num'       => $stats['task_worker_num'] ?? 0,
                            'task_idle_worker_num'  => $stats['task_idle_worker_num'] ?? 0,
                            'task_worker_num_use'   => (($stats['task_worker_num'] ?? 0) - ($stats['task_idle_worker_num'] ?? 0))
                                . '/' . ($stats['task_worker_num'] ?? 0),
                            'start_time'            => ($stats['start_time'] ?? 0)
                                . ' (' . date('Y-m-d H:i:s', $stats['start_time'] ?? 0) . ')',
                            'connection_num'        => $stats['connection_num'] ?? 0,
                            'request_count'         => $stats['request_count'] ?? 0,
                            'tasking_num'           => $stats['tasking_num'] ?? 0,
                        ],
                    ], JSON_UNESCAPED_UNICODE);

                    Redis::hset(self::PODS_HASH_KEY, $hostname, $data);
                }
            } catch (\Throwable $e) {
                Log::warning('[SwooleStatsCollector] 采集异常: ' . $e->getMessage());
            }

            sleep(self::POLL_INTERVAL);
        }
    }

    /**
     * @param Server  $swoole
     * @param Process $process
     */
    public static function onReload(Server $swoole, Process $process)
    {
        Log::info('[SwooleStatsCollector] reloading');
        // 清理本 pod 在 Hash 中的数据
        try {
            Redis::hdel(self::PODS_HASH_KEY, gethostname());
        } catch (\Throwable $e) {
            // ignore
        }
        $process->exit(0);
    }

    /**
     * @param Server  $swoole
     * @param Process $process
     */
    public static function onStop(Server $swoole, Process $process)
    {
        Log::info('[SwooleStatsCollector] stopping');
        // 清理本 pod 在 Hash 中的数据
        try {
            Redis::hdel(self::PODS_HASH_KEY, gethostname());
        } catch (\Throwable $e) {
            // ignore
        }
        $process->exit(0);
    }
}

