<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\AppStats\Sections;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Mallto\Tool\Domain\AppStats\Contracts\AppStatsSectionProvider;
use Throwable;

/**
 * 从 RedisMemoryMonitor 的单个最新状态 Key 生成 AppStats 全局区块。
 *
 * 该类运行在管理页面请求链路中，因此必须保持严格只读：一次 read() 最多解析一个命名
 * Redis 连接并执行一次 GET。不得在这里调用 RedisMemoryMonitor::run()、INFO、SET/SETEX，
 * 也不得补写告警或清理 Key；否则每次页面自动刷新都会变成一次采样和状态变更，在多 Pod
 * 下还会放大连接与日志负载。真正采样仍只由现有 Laravel Scheduler 每分钟执行一次。
 */
final class RedisMemoryAppStatsSection implements AppStatsSectionProvider
{
    private readonly Closure $clock;

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly ConfigRepository $config,
        ?Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function key(): string
    {
        return 'redis_memory';
    }

    /**
     * 返回最新一次 Scheduler 样本的固定白名单视图。
     *
     * 连接名必须与 `database.redis_monitor.connection` 完全一致：Monitor 的 INFO 与状态 Key
     * 都写在这套共享 Redis/逻辑 DB，页面若改用 default 或本地缓存连接，会在多 Pod 部署中
     * 产生“采样存在但页面一直等待”的假故障。这里只消费启动期 config() 快照，不读取 env()。
     *
     * @return array{
     *   title: string,
     *   status: string,
     *   sampled_at: ?string,
     *   metrics: array<string, int|float|string|null>,
     *   message: ?string
     * }
     */
    public function read(): array
    {
        if (! (bool) $this->config->get('database.redis_monitor.enabled', false)) {
            // 关闭时不能为了“显示上次数据”建立 Redis 连接；历史样本即使存在也不再代表监控正常。
            return $this->result('disabled', null, [], 'redis_memory_monitor_disabled');
        }

        try {
            $connectionName = (string) $this->config->get(
                'database.redis_monitor.connection',
                'redis-monitor'
            );
            $stateKey = (string) $this->config->get(
                'database.redis_monitor.state_key',
                'easy-tool:redis-memory-monitor:state'
            );

            // 页面请求唯一允许的 Redis 命令。禁止改为 MGET/INFO 或附带修复写入。
            $raw = $this->redis->connection($connectionName)->get($stateKey);
            if (! is_string($raw) || $raw === '') {
                return $this->result('waiting', null, [], 'redis_memory_waiting_for_first_sample');
            }

            $state = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($state)) {
                return $this->result('waiting', null, [], 'redis_memory_waiting_for_complete_sample');
            }

            $sampledAt = max(0, (int) ($state['last_sampled_at'] ?? 0));
            $snapshot = is_array($state['snapshot'] ?? null) ? $state['snapshot'] : null;

            // 滚动发布时旧 Monitor 仍可能覆盖成 schema v1。旧格式缺少完整 snapshot，不能把
            // 缺失数字补成 0 或继续沿用旧 level；等待下一次 v2 Scheduler 采样最不易误导运维。
            if (($state['schema_version'] ?? null) !== 2 || $sampledAt === 0 || $snapshot === null) {
                return $this->result('waiting', null, [], 'redis_memory_waiting_for_complete_sample');
            }

            $staleSeconds = max(
                1,
                (int) $this->config->get('app_stats.redis_memory_stale_seconds', 120)
            );
            $delayed = (($this->clock)() - $sampledAt) > $staleSeconds;
            $status = $delayed ? 'delayed' : $this->normalizeLevel($state['level'] ?? null);

            // freshness 以 Scheduler 采样时间为准，而不是页面读取时间。超过两个一分钟周期后，
            // 即使最后一次 level=normal 也必须展示 delayed，防止停掉 Scheduler 后页面长期报正常。
            return $this->result(
                $status,
                date(DATE_ATOM, $sampledAt),
                $this->whitelistMetrics($snapshot, $state['evicted_keys_delta'] ?? null),
                $delayed ? 'redis_memory_sample_delayed' : null
            );
        } catch (Throwable) {
            // 页面不写告警日志；聚合层只收到固定错误码，连接地址与客户端异常消息不会泄漏。
            return $this->result('error', null, [], 'redis_memory_state_read_failed');
        }
    }

    /** 未识别的状态不能直接进入 JSON schema，按 error 安全降级。 */
    private function normalizeLevel($level): string
    {
        return in_array($level, ['normal', 'warning', 'error'], true) ? $level : 'error';
    }

    /**
     * 对状态 Key 再做一次输出白名单和类型归一化。
     *
     * 即使 Redis 中残留人工写入或旧版本数据，数值字段仍只返回 number/null，不用 `N/A`
     * 混入 API；前端负责把 null 渲染成不可用。累计计数均截断到非负数。
     *
     * @return array<string, int|float|string|null>
     */
    private function whitelistMetrics(array $snapshot, $evictedKeysDelta): array
    {
        $integer = static fn (string $key): ?int => isset($snapshot[$key]) && is_numeric($snapshot[$key])
            ? max(0, (int) $snapshot[$key])
            : null;
        $decimal = static fn (string $key): ?float => isset($snapshot[$key]) && is_numeric($snapshot[$key])
            ? max(0.0, (float) $snapshot[$key])
            : null;

        return [
            'used_memory' => $integer('used_memory'),
            'used_memory_rss' => $integer('used_memory_rss'),
            'maxmemory' => $integer('maxmemory'),
            'used_percent' => $decimal('used_percent'),
            'mem_fragmentation_ratio' => $decimal('mem_fragmentation_ratio'),
            'maxmemory_policy' => is_string($snapshot['maxmemory_policy'] ?? null)
                ? $snapshot['maxmemory_policy']
                : null,
            'evicted_keys' => $integer('evicted_keys'),
            'evicted_keys_delta' => is_numeric($evictedKeysDelta)
                ? max(0, (int) $evictedKeysDelta)
                : null,
            'rejected_connections' => $integer('rejected_connections'),
        ];
    }

    /**
     * @param  array<string, int|float|string|null>  $metrics
     * @return array{
     *   title: string,
     *   status: string,
     *   sampled_at: ?string,
     *   metrics: array<string, int|float|string|null>,
     *   message: ?string
     * }
     */
    private function result(string $status, ?string $sampledAt, array $metrics, ?string $message): array
    {
        return [
            'title' => 'Redis 内存监控',
            'status' => $status,
            'sampled_at' => $sampledAt,
            'metrics' => $metrics,
            'message' => $message,
        ];
    }
}
