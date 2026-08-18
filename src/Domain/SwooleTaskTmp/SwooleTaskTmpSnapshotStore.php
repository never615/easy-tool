<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\SwooleTaskTmp;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * 以独立 Hash/ZSet 保存每个 Pod 的 task_tmp 快照，避免与 Swoole 主快照并发覆写。
 */
class SwooleTaskTmpSnapshotStore
{
    private readonly Closure $clock;

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly ConfigRepository $config,
        ?Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function store(string $hostname, array $payload, int $sampledAt): void
    {
        $json = json_encode(
            $this->normalizePayload($hostname, $payload, max(0, $sampledAt), 0),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        // task_tmp 使用独立 Key，两个写入仍在同一 pipeline 中，避免增加采样网络往返。
        $this->connection()->pipeline(function ($pipe) use ($hostname, $json, $sampledAt): void {
            $pipe->hset($this->hashKey(), $hostname, $json);
            $pipe->zadd($this->lastSeenKey(), max(0, $sampledAt), $hostname);
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function readAll(): array
    {
        return $this->readSnapshots(false);
    }

    /**
     * 返回可解析的快照候选，包括过期或缺少索引的值，供 AppStats 区分不可用原因。
     *
     * @return array<string, array<string, mixed>>
     */
    public function readCandidates(): array
    {
        return $this->readSnapshots(true);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readSnapshots(bool $includeUnavailable): array
    {
        $connection = $this->connection();
        // 每次 AppStats 读取固定为一个 Hash 和一个 WITHSCORES ZSet 请求；不做全库扫描或修复写入。
        $payloads = (array) $connection->hgetall($this->hashKey());
        $scores = $this->normalizeScores((array) $connection->zrange(
            $this->lastSeenKey(),
            0,
            -1,
            ['withscores' => true]
        ));
        $now = ($this->clock)();
        $staleSeconds = max(1, (int) $this->config->get('app_stats.task_tmp_snapshot_stale_seconds', 60));
        $snapshots = [];

        foreach ($payloads as $hostname => $json) {
            $payload = json_decode((string) $json, true);
            if (! is_array($payload)) {
                continue;
            }

            // Hash 与 ZSet 共同构成快照：没有 ZSet 时间戳的孤立 Hash 值不可信，不能回退到
            // payload 内的旧时间戳，否则旧实例数据可能被错误合并到当前 Pod。
            $hasScore = array_key_exists((string) $hostname, $scores);
            $sampledAt = $hasScore ? max(0, (int) $scores[(string) $hostname]) : 0;
            $sampleAgeSeconds = $sampledAt > 0 ? max(0, $now - $sampledAt) : PHP_INT_MAX;

            if ((! $hasScore || $sampledAt === 0 || $sampleAgeSeconds > $staleSeconds)
                && ! $includeUnavailable) {
                continue;
            }

            $snapshots[(string) $hostname] = $this->normalizePayload(
                (string) $hostname,
                $payload,
                $sampledAt,
                $sampleAgeSeconds
            );
        }

        return $snapshots;
    }

    public function forget(string $hostname): void
    {
        $this->connection()->pipeline(function ($pipe) use ($hostname): void {
            $pipe->hdel($this->hashKey(), $hostname);
            $pipe->zrem($this->lastSeenKey(), $hostname);
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(
        string $hostname,
        array $payload,
        int $sampledAt,
        int $sampleAgeSeconds
    ): array
    {
        return [
            // Hash field 是 task tmp 快照的权威 hostname，忽略 payload 内的同名字段。
            'hostname' => $hostname,
            'instance_id' => is_string($payload['instance_id'] ?? null) ? $payload['instance_id'] : '',
            'updated_at' => $sampledAt,
            'sample_age_seconds' => $sampleAgeSeconds,
            'path' => is_string($payload['path'] ?? null) ? $payload['path'] : null,
            'process_enabled' => (bool) ($payload['process_enabled'] ?? false),
            'auto_cleanup_enabled' => (bool) ($payload['auto_cleanup_enabled'] ?? false),
            'filesystem' => $this->normalizeFields($payload['filesystem'] ?? null, [
                'ok' => null,
                'total_bytes' => null,
                'free_bytes' => null,
                'used_bytes' => null,
                'used_percent' => null,
                'error_type' => null,
            ]),
            'inventory' => $this->normalizeFields($payload['inventory'] ?? null, [
                'scanned' => null,
                'matching_files' => 'matchingFiles',
                'matching_bytes' => 'matchingBytes',
                'stale_files' => 'staleFiles',
                'stale_bytes' => 'staleBytes',
                'oldest_mtime' => 'oldestMtime',
                'complete' => null,
                'duration_milliseconds' => 'durationMilliseconds',
                'error_type' => 'errorType',
                'started_at' => null,
                'finished_at' => null,
            ]),
            'cleanup' => $this->normalizeFields($payload['cleanup'] ?? null, [
                'request_id' => null,
                'status' => null,
                'started_at' => null,
                'finished_at' => null,
                'scanned' => null,
                'deleted' => null,
                'failed' => null,
                'error_type' => 'errorType',
            ]),
        ];
    }

    /**
     * @param mixed $fields
     * @param array<string, ?string> $allowed
     * @return array<string, scalar|null>
     */
    private function normalizeFields(mixed $fields, array $allowed): array
    {
        if (! is_array($fields)) {
            return [];
        }

        $normalized = [];
        foreach ($allowed as $snakeCase => $camelCase) {
            $value = $fields[$snakeCase] ?? ($camelCase === null ? null : ($fields[$camelCase] ?? null));
            if (is_scalar($value) || $value === null) {
                $normalized[$snakeCase] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param array<array-key, mixed> $scores
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

    private function connection()
    {
        $name = $this->config->get('app_stats.redis_connection');

        return $this->redis->connection(is_string($name) && $name !== '' ? $name : null);
    }

    private function hashKey(): string
    {
        return (string) $this->config->get(
            'app_stats.task_tmp_pods_hash_key',
            'app_stats:swoole_task_tmp:pods'
        );
    }

    private function lastSeenKey(): string
    {
        return (string) $this->config->get(
            'app_stats.task_tmp_pods_last_seen_key',
            'app_stats:swoole_task_tmp:pods:last_seen'
        );
    }
}
