<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\AppStats;

/**
 * 将单个 LaravelS 实例的原始 Swoole stats 归一化为稳定的 schema-v2 快照。
 *
 * metrics 保留纯数值，便于页面聚合、阈值判断和后续机器消费；stats 同时保留旧页面依赖的
 * 展示字符串。业务包仍可通过额外顶层分区扩展快照，但不能覆盖协议保留字段。
 */
final class SwooleStatsSnapshotFactory
{
    /**
     * @param array<string, mixed> $stats Swoole Server::stats() 返回值
     * @param array<array-key, mixed> $extra 业务包追加的顶层监控分区
     * @return array<string, mixed>
     */
    public function make(
        string $hostname,
        int $masterPid,
        int $cpuNum,
        array $stats,
        array $extra,
        int $sampledAt
    ): array {
        $workerTotal = max(0, (int)($stats['worker_num'] ?? 0));
        $workerIdle = min($workerTotal, max(0, (int)($stats['idle_worker_num'] ?? 0)));
        $taskTotal = max(0, (int)($stats['task_worker_num'] ?? 0));
        $taskIdle = min($taskTotal, max(0, (int)($stats['task_idle_worker_num'] ?? 0)));
        $startTime = max(0, (int)($stats['start_time'] ?? 0));

        $payload = [
            'schema_version' => 2,
            'instance_id' => sprintf('%s:%d:%d', $hostname, $startTime, max(0, $masterPid)),
            'hostname' => $hostname,
            'updated_at' => $sampledAt,
            'metrics' => [
                'swoole_cpu_num' => max(0, $cpuNum),
                'worker_num' => $workerTotal,
                'idle_worker_num' => $workerIdle,
                'task_worker_num' => $taskTotal,
                'task_idle_worker_num' => $taskIdle,
                'start_time' => $startTime,
                'connection_num' => max(0, (int)($stats['connection_num'] ?? 0)),
                'request_count' => max(0, (int)($stats['request_count'] ?? 0)),
                'tasking_num' => max(0, (int)($stats['tasking_num'] ?? 0)),
            ],
            'stats' => [
                'swoole_cpu_num' => max(0, $cpuNum),
                'worker_num_use' => ($workerTotal - $workerIdle).'/'.$workerTotal,
                'task_worker_num_use' => ($taskTotal - $taskIdle).'/'.$taskTotal,
                'start_time' => $startTime.' ('.date('Y-m-d H:i:s', $startTime).')',
                'connection_num' => max(0, (int)($stats['connection_num'] ?? 0)),
                'request_count' => max(0, (int)($stats['request_count'] ?? 0)),
                'tasking_num' => max(0, (int)($stats['tasking_num'] ?? 0)),
            ],
        ];

        $reserved = ['schema_version', 'instance_id', 'hostname', 'updated_at', 'metrics', 'stats'];
        foreach ($extra as $key => $value) {
            if (is_string($key) && ! in_array($key, $reserved, true)) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }
}
