<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Processes;

use Hhxsv5\LaravelS\Swoole\Process\CustomProcessInterface;
use Illuminate\Support\Facades\Log;
use Mallto\Tool\Domain\AppStats\AppStatsPodWriter;
use Mallto\Tool\Domain\AppStats\SwooleStatsSnapshotFactory;
use Swoole\Http\Server;
use Swoole\Process;
use Throwable;

/**
 * Swoole 状态采集进程
 *
 * 每个 Pod 中各运行一个实例。进程持续每 5 秒采集本实例的 Swoole stats，并把 schema-v2
 * 快照写入共享 Redis，供只读监控页面跨 Pod 聚合。页面请求不会启动、停止或驱动采集。
 *
 * Redis 键：
 *   swoole_stats:pods            — Hash，Field = hostname，Value = schema-v2 Pod JSON
 *   swoole_stats:pods:last_seen  — ZSet，Member = hostname，Score = 最近采样 Unix 时间
 *
 * 扩展方式：在业务包中继承本类并覆盖 collectExtraStats()，
 * 然后在 config/laravels.php 的 processes 中将 class 替换为子类。
 *
 * User: never615 <never615.com>
 * Date: 2026/3/22
 */
class SwooleStatsCollectorProcess implements CustomProcessInterface
{
    /**
     * 已废弃的采集开关 Redis 键，仅保留一个发布周期供旧 Controller 常量引用。
     *
     * 新采集进程不会读取或写入此 Key。
     */
    public const COLLECTING_KEY = 'swoole_stats:collecting';

    /**
     * 各 pod 指标存储 Redis Hash 键
     */
    public const PODS_HASH_KEY = 'swoole_stats:pods';

    /**
     * 各 Pod 最近采样时间 Redis ZSet 键。
     */
    public const PODS_LAST_SEEN_KEY = 'swoole_stats:pods:last_seen';

    /**
     * pod 数据过期时间（秒）：超过此时间未更新则视为下线
     */
    public const POD_DATA_TTL = 15;

    /**
     * 进程运行标志，onReload/onStop 置 false 通知主循环退出
     */
    private static bool $running = true;

    /**
     * @param Server  $swoole
     * @param Process $process
     */
    public static function callback(Server $swoole, Process $process)
    {
        self::$running = true;
        // 常驻进程内只解析一次依赖，避免每 5 秒通过容器重复构造 Writer/Factory。
        $writer = app(AppStatsPodWriter::class);
        $factory = app(SwooleStatsSnapshotFactory::class);
        $hostname = gethostname() ?: 'unknown';
        $lastCleanupAt = 0;

        Log::info("[SwooleStatsCollector] 进程启动，hostname: {$hostname}");

        while (self::$running) {
            $now = time();

            try {
                $stats = $swoole->stats();
                $extra = [];

                // 业务扩展采集失败时仍写入基础 Swoole 快照；日志只记录异常类型，避免泄露连接信息。
                try {
                    $extra = static::collectExtraStats($swoole, $hostname);
                } catch (Throwable $exception) {
                    Log::warning('[SwooleStatsCollector] extra stats failed', [
                        'error_type' => $exception::class,
                    ]);
                }

                $writer->store($hostname, $factory->make(
                    $hostname,
                    (int)($swoole->master_pid ?? getmypid()),
                    swoole_cpu_num(),
                    $stats,
                    $extra,
                    $now
                ), $now);

                if (($now - $lastCleanupAt) >= max(1, (int)config('app_stats.cleanup_interval_seconds', 60))) {
                    // 在执行前记录尝试时间：即使共享 Redis 短暂失败，也不会退化为每 5 秒清理一次。
                    $lastCleanupAt = $now;
                    $writer->cleanup($now);
                }
            } catch (Throwable $exception) {
                // 不记录底层异常消息，防止 Redis 地址、凭据或业务扩展 payload 进入日志。
                Log::warning('[SwooleStatsCollector] sample failed', [
                    'error_type' => $exception::class,
                ]);
            }

            self::sleepWhileRunning(max(1, (int)config('app_stats.collect_interval_seconds', 5)));
        }

        // 主循环退出后，在协程上下文中同时清理 Hash 与 ZSet；信号回调本身不做阻塞 IO。
        try {
            $writer->forget($hostname);
        } catch (Throwable $exception) {
            Log::warning('[SwooleStatsCollector] forget failed', [
                'error_type' => $exception::class,
            ]);
        }

        Log::info("[SwooleStatsCollector] 进程退出，hostname: {$hostname}");
    }

    /**
     * 将较长的采集间隔拆成 0.1 秒短休眠，使 LaravelS reload/stop 能快速终止主循环。
     */
    private static function sleepWhileRunning(int $seconds): void
    {
        for ($tick = 0; $tick < $seconds * 10 && self::$running; $tick++) {
            \Swoole\Coroutine::sleep(0.1);
        }
    }

    /**
     * 额外业务指标采集扩展点，子类覆盖此方法以追加自定义指标。
     *
     * 返回关联数组，每个 key 会作为独立字段写入 per-pod payload（与 'stats' 同级）。
     * 例如：['skylab_tcp' => ['skylab_connection_count' => 12, ...]]
     *
     * @param Server $swoole
     * @param string $hostname
     * @return array<string, array>
     */
    protected static function collectExtraStats(Server $swoole, string $hostname): array
    {
        return [];
    }

    /**
     * @param Server  $swoole
     * @param Process $process
     */
    public static function onReload(Server $swoole, Process $process)
    {
        Log::info('[SwooleStatsCollector] reloading');
        // 仅设置标志位，让主循环自行退出并清理，避免在信号上下文中做阻塞 IO 导致协程死锁
        self::$running = false;
    }

    /**
     * @param Server  $swoole
     * @param Process $process
     */
    public static function onStop(Server $swoole, Process $process)
    {
        Log::info('[SwooleStatsCollector] stopping');
        // 仅设置标志位，让主循环自行退出并清理，避免在信号上下文中做阻塞 IO 导致协程死锁
        self::$running = false;
    }
}
