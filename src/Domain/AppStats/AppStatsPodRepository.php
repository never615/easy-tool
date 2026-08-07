<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\AppStats;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Mallto\Tool\Domain\SwooleTaskTmp\SwooleTaskTmpSnapshotStore;
use Throwable;

/**
 * 从共享 Redis 中建立当前集群的 Pod 只读视图。
 *
 * 每次 read() 固定只执行一次 HGETALL 和一次 ZRANGE WITHSCORES。过期、非法或滚动发布期间
 * 暂时缺少 ZSet score 的数据只在内存中分类，不执行清理或修复写入；后台 writer 独立负责清理。
 */
class AppStatsPodRepository
{
    private readonly Closure $clock;

    /**
     * 注入本组件运行所需的协作依赖，保持构造与业务执行解耦。
     */
    public function __construct(
        private readonly RedisFactory $redis,
        private readonly ConfigRepository $config,
        private readonly SwooleTaskTmpSnapshotStore $taskTmpSnapshots,
        ?Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @return array{
     *   ok: bool,
     *   message: ?string,
     *   pods: array<int, array<string, mixed>>,
     *   online_pods: int,
     *   delayed_pods: int,
     *   expired_pods: int,
     *   invalid_pod_snapshots: int
     * }
     */
    public function read(): array
    {
        $taskTmpSnapshots = $this->readTaskTmpSnapshots();

        try {
            $connection = $this->connection();
            $payloads = (array) $connection->hgetall($this->hashKey());
            $rawScores = (array) $connection->zrange(
                $this->lastSeenKey(),
                0,
                -1,
                ['withscores' => true]
            );
            $scores = $this->normalizeScores($rawScores);
            $now = ($this->clock)();
            $pods = [];
            $counts = ['online' => 0, 'delayed' => 0, 'expired' => 0];
            $invalid = 0;

            foreach ($payloads as $hostname => $json) {
                $payload = json_decode((string) $json, true);
                if (! is_array($payload)) {
                    // 非法内容只贡献计数；既不返回原始 payload，也不暴露 JSON 解析细节。
                    $invalid++;

                    continue;
                }

                // rolling release 期间 Hash 可能先于 ZSet 可见，使用 payload 时间保持向后兼容。
                $lastSeen = max(0, (int) ($scores[(string) $hostname] ?? $payload['updated_at'] ?? 0));
                $age = max(0, $now - $lastSeen);
                $status = $this->statusForAge($age);
                $counts[$status]++;

                if ($status === 'expired') {
                    continue;
                }

                $pods[] = $this->normalizePod(
                    (string) $hostname,
                    $payload,
                    $lastSeen,
                    $age,
                    $status,
                    $taskTmpSnapshots[(string) $hostname] ?? null
                );
            }

            // 固定排序避免 Redis Hash 返回顺序影响 API、页面 diff 和自动化测试。
            usort(
                $pods,
                static fn (array $left, array $right): int => $left['hostname'] <=> $right['hostname']
            );

            return [
                'ok' => true,
                'message' => null,
                'pods' => $pods,
                'online_pods' => $counts['online'],
                'delayed_pods' => $counts['delayed'],
                'expired_pods' => $counts['expired'],
                'invalid_pod_snapshots' => $invalid,
            ];
        } catch (Throwable) {
            // 外部错误只映射为稳定机器码，不把 Redis 地址、认证信息或底层异常带给页面。
            return $this->failedState();
        }
    }

    /**
     * WITHSCORES 在常用 Redis 客户端中可能返回 member => score 关联数组，也可能返回
     * [member, score, member, score] 扁平数组；统一后上层 freshness 逻辑无需感知驱动差异。
     *
     * @param  array<array-key, mixed>  $scores
     * @return array<string, int>
     */
    private function normalizeScores(array $scores): array
    {
        if (! array_is_list($scores)) {
            $normalized = [];
            foreach ($scores as $hostname => $score) {
                if (is_scalar($hostname) && is_numeric($score)) {
                    $normalized[(string) $hostname] = max(0, (int) $score);
                }
            }

            return $normalized;
        }

        $normalized = [];
        $count = count($scores);
        for ($index = 0; $index + 1 < $count; $index += 2) {
            $hostname = $scores[$index];
            $score = $scores[$index + 1];
            if ((is_string($hostname) || is_int($hostname)) && is_numeric($score)) {
                $normalized[(string) $hostname] = max(0, (int) $score);
            }
        }

        return $normalized;
    }

    /**
     * 将 schema-v2 或旧版 stats payload 归一化为固定数值指标。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePod(
        string $hostname,
        array $payload,
        int $lastSeen,
        int $age,
        string $status,
        ?array $taskTmpSnapshot
    ): array {
        $metrics = is_array($payload['metrics'] ?? null) ? $payload['metrics'] : [];
        $stats = is_array($payload['stats'] ?? null) ? $payload['stats'] : [];

        if ($metrics !== []) {
            $workerTotal = max(0, (int) ($metrics['worker_num'] ?? 0));
            $workerIdle = min($workerTotal, max(0, (int) ($metrics['idle_worker_num'] ?? 0)));
            $taskTotal = max(0, (int) ($metrics['task_worker_num'] ?? 0));
            $taskIdle = min($taskTotal, max(0, (int) ($metrics['task_idle_worker_num'] ?? 0)));
            $workerUsed = $workerTotal - $workerIdle;
            $taskUsed = $taskTotal - $taskIdle;
        } else {
            [$workerUsed, $workerTotal] = $this->parseUsage((string) ($stats['worker_num_use'] ?? '0/0'));
            [$taskUsed, $taskTotal] = $this->parseUsage(
                (string) ($stats['task_worker_num_use'] ?? '0/0')
            );
        }

        return [
            // Hash field 是共享存储中的权威标识，不能被 payload 内同名字段伪造。
            'hostname' => $hostname,
            'instance_id' => (string) ($payload['instance_id'] ?? sprintf(
                '%s:%d:0',
                $hostname,
                max(0, (int) ($payload['updated_at'] ?? 0))
            )),
            'updated_at' => max(0, (int) ($payload['updated_at'] ?? $lastSeen)),
            'last_seen_at' => $lastSeen,
            'sample_age_seconds' => $age,
            'status' => $status,
            'metrics' => [
                'worker_used' => $workerUsed,
                'worker_total' => $workerTotal,
                'task_worker_used' => $taskUsed,
                'task_worker_total' => $taskTotal,
                'tasking_num' => max(0, (int) ($metrics['tasking_num'] ?? $stats['tasking_num'] ?? 0)),
                'connection_num' => max(
                    0,
                    (int) ($metrics['connection_num'] ?? $stats['connection_num'] ?? 0)
                ),
                'request_count' => max(
                    0,
                    (int) ($metrics['request_count'] ?? $stats['request_count'] ?? 0)
                ),
                'start_time' => max(0, (int) ($metrics['start_time'] ?? 0)),
            ],
            'stats' => $stats,
            'skylab_tcp' => is_array($payload['skylab_tcp'] ?? null) ? $payload['skylab_tcp'] : [],
            'task_tmp' => $this->normalizeTaskTmp(
                $hostname,
                (string) ($payload['instance_id'] ?? sprintf(
                    '%s:%d:0',
                    $hostname,
                    max(0, (int) ($payload['updated_at'] ?? 0))
                )),
                $taskTmpSnapshot
            ),
        ];
    }

    /**
     * task tmp 存储不可用不应隐藏仍可读取的 Swoole Pod 快照；页面以固定 unavailable 状态降级。
     *
     * @return array<string, array<string, mixed>>
     */
    private function readTaskTmpSnapshots(): array
    {
        try {
            return $this->taskTmpSnapshots->readAll();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed>|null $snapshot
     * @return array<string, mixed>
     */
    private function normalizeTaskTmp(string $hostname, string $instanceId, ?array $snapshot): array
    {
        if ($snapshot === null
            || ! is_string($snapshot['hostname'] ?? null)
            || $snapshot['hostname'] !== $hostname
            || ! is_string($snapshot['instance_id'] ?? null)
            || $snapshot['instance_id'] === ''
            || $snapshot['instance_id'] !== $instanceId
            || max(0, (int) ($snapshot['sample_age_seconds'] ?? PHP_INT_MAX))
                > max(1, (int) $this->config->get('app_stats.task_tmp_snapshot_stale_seconds', 60))) {
            return $this->unavailableTaskTmp();
        }

        return [
            'status' => 'normal',
            'sample_age_seconds' => max(0, (int) $snapshot['sample_age_seconds']),
            'instance_id' => $snapshot['instance_id'],
            'path' => is_string($snapshot['path'] ?? null) ? $snapshot['path'] : null,
            'process_enabled' => (bool) ($snapshot['process_enabled'] ?? false),
            'auto_cleanup_enabled' => (bool) ($snapshot['auto_cleanup_enabled'] ?? false),
            'filesystem' => $this->taskTmpFields($snapshot['filesystem'] ?? null, [
                'ok', 'total_bytes', 'free_bytes', 'used_bytes', 'used_percent', 'error_type',
            ]),
            'inventory' => $this->taskTmpFields($snapshot['inventory'] ?? null, [
                'scanned', 'matching_files', 'matching_bytes', 'stale_files', 'stale_bytes',
                'oldest_mtime', 'complete', 'duration_milliseconds', 'error_type', 'started_at',
                'finished_at',
            ]),
            'cleanup' => $this->taskTmpFields($snapshot['cleanup'] ?? null, [
                'request_id', 'status', 'started_at', 'finished_at', 'scanned', 'deleted', 'failed',
                'error_type',
            ]),
        ];
    }

    /**
     * @param mixed $fields
     * @param array<int, string> $allowed
     * @return array<string, scalar|null>
     */
    private function taskTmpFields(mixed $fields, array $allowed): array
    {
        if (! is_array($fields)) {
            return [];
        }

        $normalized = [];
        foreach ($allowed as $field) {
            $value = $fields[$field] ?? null;
            if (is_scalar($value) || $value === null) {
                $normalized[$field] = $value;
            }
        }

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function unavailableTaskTmp(): array
    {
        return [
            'status' => 'unavailable',
            'sample_age_seconds' => null,
            'instance_id' => null,
            'path' => null,
            'process_enabled' => false,
            'auto_cleanup_enabled' => false,
            'filesystem' => [],
            'inventory' => [],
            'cleanup' => [],
        ];
    }

    /** @return array{0: int, 1: int} */
    private function parseUsage(string $value): array
    {
        if (preg_match('/^(\d+)\/(\d+)$/', $value, $matches) !== 1) {
            return [0, 0];
        }

        $total = max(0, (int) $matches[2]);

        return [min($total, max(0, (int) $matches[1])), $total];
    }

    /**
     * 处理「statusForAge」相关的内部协作步骤。
     */
    private function statusForAge(int $age): string
    {
        if ($age <= max(1, (int) $this->config->get('app_stats.online_seconds', 15))) {
            return 'online';
        }

        if ($age <= max(1, (int) $this->config->get('app_stats.delayed_seconds', 60))) {
            return 'delayed';
        }

        return 'expired';
    }

    /**
     * @return array{
     *   ok: false,
     *   message: string,
     *   pods: array<never, never>,
     *   online_pods: 0,
     *   delayed_pods: 0,
     *   expired_pods: 0,
     *   invalid_pod_snapshots: 0
     * }
     */
    private function failedState(): array
    {
        return [
            'ok' => false,
            'message' => 'pod_store_read_failed',
            'pods' => [],
            'online_pods' => 0,
            'delayed_pods' => 0,
            'expired_pods' => 0,
            'invalid_pod_snapshots' => 0,
        ];
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
        return (string) $this->config->get('app_stats.pods_hash_key', 'swoole_stats:pods');
    }

    /**
     * 处理「lastSeenKey」相关的内部协作步骤。
     */
    private function lastSeenKey(): string
    {
        return (string) $this->config->get('app_stats.pods_last_seen_key', 'swoole_stats:pods:last_seen');
    }
}
