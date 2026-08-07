<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\SwooleTaskTmp;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use InvalidArgumentException;
use JsonException;

/**
 * 协调每个 Pod 在其本地 task_tmpdir 执行一次手动清理。
 *
 * Redis 只保存有时限的控制面状态；任务临时文件始终由目标 Pod 在本地文件系统中处理。
 */
final class SwooleTaskTmpCleanupRequestStore implements SwooleTaskTmpCleanupRequestCoordinator
{
    /** @var list<string> */
    private const TERMINAL_STATUSES = ['succeeded', 'failed', 'timed_out'];

    /** @var list<string> */
    private const RESULT_STATUSES = ['pending', 'running', 'succeeded', 'failed', 'timed_out'];

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly ConfigRepository $config
    ) {
    }

    /**
     * @param list<array{hostname: string, instance_id: string}> $targets
     * @return array{created: bool, request_id: string}
     */
    public function create(array $targets, int|string $requestedBy, int $now): array
    {
        $targets = $this->normalizeTargets($targets);
        $now = max(0, $now);
        $requestId = bin2hex(random_bytes(16));
        $deadlineAt = $now + $this->executionTimeoutSeconds();
        $request = [
            'request_id' => $requestId,
            'requested_by' => $requestedBy,
            'created_at' => $now,
            'deadline_at' => $deadlineAt,
            'targets' => $targets,
        ];
        $pendingResult = $this->encode([
            'status' => 'pending',
            'updated_at' => $now,
        ]);
        $arguments = [
            $requestId,
            $this->encode($request),
            $now,
            $deadlineAt,
            $this->retentionSeconds(),
            $this->deadlinePrefix(),
        ];

        foreach ($targets as $target) {
            $arguments[] = $target['hostname'];
            $arguments[] = $pendingResult;
        }

        $response = $this->connection()->eval(
            <<<'LUA'
-- create_cleanup_request
local active_request_id = redis.call('GET', KEYS[1])
if active_request_id then
    local active_deadline = redis.call('GET', ARGV[6] .. active_request_id)
    if active_deadline and tonumber(active_deadline) > tonumber(ARGV[3]) then
        return {0, active_request_id}
    end
end

redis.call('SET', KEYS[1], ARGV[1], 'EX', ARGV[5])
redis.call('SET', KEYS[2], ARGV[2], 'EX', ARGV[5])
redis.call('SET', KEYS[3], ARGV[4], 'EX', ARGV[5])
for index = 7, #ARGV, 2 do
    redis.call('HSET', KEYS[4], ARGV[index], ARGV[index + 1])
end
redis.call('EXPIRE', KEYS[4], ARGV[5])

return {1, ARGV[1]}
LUA,
            4,
            $this->activeKey(),
            $this->requestKey($requestId),
            $this->deadlineKey($requestId),
            $this->resultsKey($requestId),
            ...$arguments
        );

        $created = is_array($response) && (int) ($response[0] ?? 0) === 1;
        $activeRequestId = is_array($response) ? (string) ($response[1] ?? '') : '';

        if ($activeRequestId === '') {
            throw new InvalidArgumentException('Unable to create task tmp cleanup request.');
        }

        return [
            'created' => $created,
            'request_id' => $activeRequestId,
        ];
    }

    /**
     * @return array{request_id: string, deadline_at: int}|null
     */
    public function claim(string $hostname, string $instanceId, int $now): ?array
    {
        $requestId = $this->readString($this->activeKey());
        if ($requestId === null) {
            return null;
        }

        $response = $this->connection()->eval(
            <<<'LUA'
-- claim_cleanup_target
local request_id = redis.call('GET', KEYS[1])
if request_id == false then
    return false
end

local deadline_at = redis.call('GET', KEYS[3])
if request_id ~= ARGV[1] or deadline_at == false or tonumber(deadline_at) <= tonumber(ARGV[4]) then
    return false
end

local request_json = redis.call('GET', KEYS[2])
if request_json == false then
    return false
end

local request = cjson.decode(request_json)
local target_matches = false
for _, target in ipairs(request.targets or {}) do
    if target.hostname == ARGV[2] and target.instance_id == ARGV[3] then
        target_matches = true
        break
    end
end
if not target_matches then
    return false
end

local result_json = redis.call('HGET', KEYS[4], ARGV[2])
if result_json == false then
    return false
end

local result = cjson.decode(result_json)
if result.status == 'succeeded' or result.status == 'failed' or result.status == 'timed_out' then
    return false
end

if redis.call('SET', KEYS[5], ARGV[1], 'EX', ARGV[5], 'NX') == false then
    return false
end

return {ARGV[1], deadline_at}
LUA,
            5,
            $this->activeKey(),
            $this->requestKey($requestId),
            $this->deadlineKey($requestId),
            $this->resultsKey($requestId),
            $this->leaseKey($requestId, $hostname, $instanceId),
            $requestId,
            $hostname,
            $instanceId,
            max(0, $now),
            $this->leaseSeconds()
        );

        if (! is_array($response) || (string) ($response[0] ?? '') === '') {
            return null;
        }

        return [
            'request_id' => (string) $response[0],
            'deadline_at' => max(0, (int) ($response[1] ?? 0)),
        ];
    }

    public function renew(string $requestId, string $hostname, string $instanceId, int $now): bool
    {
        $response = $this->connection()->eval(
            <<<'LUA'
-- renew_cleanup_lease
if redis.call('GET', KEYS[1]) ~= ARGV[1] or redis.call('EXISTS', KEYS[2]) == 0 then
    return 0
end

local deadline_at = redis.call('GET', KEYS[3])
if deadline_at == false or tonumber(deadline_at) <= tonumber(ARGV[4]) then
    return 0
end

local result_json = redis.call('HGET', KEYS[4], ARGV[2])
if result_json == false then
    return 0
end

local result = cjson.decode(result_json)
if result.status == 'succeeded' or result.status == 'failed' or result.status == 'timed_out' then
    return 0
end

if redis.call('GET', KEYS[5]) ~= ARGV[1] then
    return 0
end

redis.call('EXPIRE', KEYS[5], ARGV[5])
return 1
LUA,
            5,
            $this->activeKey(),
            $this->requestKey($requestId),
            $this->deadlineKey($requestId),
            $this->resultsKey($requestId),
            $this->leaseKey($requestId, $hostname, $instanceId),
            $requestId,
            $hostname,
            $instanceId,
            max(0, $now),
            $this->leaseSeconds()
        );

        return (int) $response === 1;
    }

    /**
     * @param array<string, mixed> $progress
     */
    public function markRunning(string $requestId, string $hostname, array $progress): void
    {
        unset($progress['status']);
        unset($progress['hostname'], $progress['instance_id']);
        $now = time();
        $this->connection()->eval(
            <<<'LUA'
-- mark_cleanup_running
if redis.call('GET', KEYS[1]) ~= ARGV[1] then
    return 0
end

local deadline_at = redis.call('GET', KEYS[3])
if deadline_at == false or tonumber(deadline_at) <= tonumber(ARGV[3]) or redis.call('EXISTS', KEYS[2]) == 0 then
    return 0
end

local existing_json = redis.call('HGET', KEYS[4], ARGV[2])
if existing_json == false then
    return 0
end

local existing = cjson.decode(existing_json)
if existing.status ~= 'pending' and existing.status ~= 'running' then
    return 0
end

redis.call('HSET', KEYS[4], ARGV[2], ARGV[4])
return 1
LUA,
            4,
            $this->activeKey(),
            $this->requestKey($requestId),
            $this->deadlineKey($requestId),
            $this->resultsKey($requestId),
            $requestId,
            $hostname,
            $now,
            $this->encode(array_merge($progress, [
                'status' => 'running',
                'updated_at' => $now,
            ]))
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    public function markFinished(string $requestId, string $hostname, string $status, array $result): void
    {
        if (! $this->isTerminal($status)) {
            throw new InvalidArgumentException('Task tmp cleanup status must be terminal.');
        }

        $request = $this->readRequest($requestId);
        if ($request === null || ! $this->hasHostname($request, $hostname)) {
            return;
        }

        unset($result['status']);
        unset($result['hostname'], $result['instance_id']);
        $this->connection()->eval(
            <<<'LUA'
-- finish_cleanup_target
if redis.call('EXISTS', KEYS[2]) == 0 or redis.call('HEXISTS', KEYS[4], ARGV[2]) == 0 then
    return 0
end

local existing = cjson.decode(redis.call('HGET', KEYS[4], ARGV[2]))
if existing.status == 'succeeded' or existing.status == 'failed' or existing.status == 'timed_out' then
    return 0
end

redis.call('HSET', KEYS[4], ARGV[2], ARGV[3])
local all_terminal = true
for _, value in ipairs(redis.call('HVALS', KEYS[4])) do
    local target = cjson.decode(value)
    if target.status ~= 'succeeded' and target.status ~= 'failed' and target.status ~= 'timed_out' then
        all_terminal = false
        break
    end
end

if all_terminal and redis.call('GET', KEYS[1]) == ARGV[1] then
    redis.call('DEL', KEYS[1])
    redis.call('SET', KEYS[5], ARGV[1], 'EX', ARGV[4])
end

return all_terminal and 2 or 1
LUA,
            5,
            $this->activeKey(),
            $this->requestKey($requestId),
            $this->deadlineKey($requestId),
            $this->resultsKey($requestId),
            $this->lastKey(),
            $requestId,
            $hostname,
            $this->encode(array_merge($result, [
                'status' => $status,
                'updated_at' => time(),
            ])),
            $this->retentionSeconds()
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentStatus(int $now): ?array
    {
        $requestId = $this->readString($this->activeKey());
        $request = $requestId === null ? null : $this->readRequest($requestId);

        if ($request === null) {
            $requestId = $this->readString($this->lastKey());
            $request = $requestId === null ? null : $this->readRequest($requestId);
        }

        if ($request === null || $requestId === null) {
            return null;
        }

        $deadlineAt = max(0, (int) ($request['deadline_at'] ?? 0));
        $resultRows = (array) $this->connection()->hgetall($this->resultsKey($requestId));
        $targets = [];

        foreach ((array) ($request['targets'] ?? []) as $target) {
            if (! is_array($target)) {
                continue;
            }

            $hostname = (string) ($target['hostname'] ?? '');
            $result = $this->decode($resultRows[$hostname] ?? null) ?? [];
            $status = (string) ($result['status'] ?? 'pending');
            if (! in_array($status, self::RESULT_STATUSES, true)) {
                $status = 'pending';
            }
            if ($now >= $deadlineAt && ! $this->isTerminal($status)) {
                $status = 'timed_out';
            }

            $targets[] = array_merge($result, [
                'hostname' => $hostname,
                'instance_id' => (string) ($target['instance_id'] ?? ''),
                'status' => $status,
            ]);
        }

        return [
            'request_id' => $requestId,
            'requested_by' => $request['requested_by'] ?? null,
            'created_at' => max(0, (int) ($request['created_at'] ?? 0)),
            'deadline_at' => $deadlineAt,
            'status' => $this->aggregateStatus($targets),
            'targets' => $targets,
        ];
    }

    /**
     * @param list<array<string, mixed>> $targets
     */
    private function aggregateStatus(array $targets): string
    {
        $statuses = array_column($targets, 'status');
        if (in_array('running', $statuses, true)) {
            return 'running';
        }
        if (in_array('pending', $statuses, true)) {
            return 'pending';
        }
        if ($statuses !== [] && count(array_filter($statuses, static fn (string $status): bool => $status === 'succeeded')) === count($statuses)) {
            return 'succeeded';
        }

        $succeeded = in_array('succeeded', $statuses, true);
        $failed = in_array('failed', $statuses, true);
        $timedOut = in_array('timed_out', $statuses, true);
        if ($succeeded && ($failed || $timedOut)) {
            return 'partial_failed';
        }
        if ($statuses !== [] && count(array_filter($statuses, static fn (string $status): bool => $status === 'timed_out')) === count($statuses)) {
            return 'timed_out';
        }
        if ($failed && ! $succeeded) {
            return 'failed';
        }

        return 'failed';
    }

    /**
     * @param array<string, mixed> $request
     */
    private function hasTarget(array $request, string $hostname, string $instanceId): bool
    {
        foreach ((array) ($request['targets'] ?? []) as $target) {
            if (is_array($target)
                && ($target['hostname'] ?? null) === $hostname
                && ($target['instance_id'] ?? null) === $instanceId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function hasHostname(array $request, string $hostname): bool
    {
        foreach ((array) ($request['targets'] ?? []) as $target) {
            if (is_array($target) && ($target['hostname'] ?? null) === $hostname) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{hostname: string, instance_id: string}> $targets
     * @return list<array{hostname: string, instance_id: string}>
     */
    private function normalizeTargets(array $targets): array
    {
        if ($targets === []) {
            throw new InvalidArgumentException('Task tmp cleanup request requires at least one target.');
        }

        $normalized = [];
        foreach ($targets as $target) {
            $hostname = is_array($target) && is_string($target['hostname'] ?? null)
                ? trim($target['hostname'])
                : '';
            $instanceId = is_array($target) && is_string($target['instance_id'] ?? null)
                ? trim($target['instance_id'])
                : '';
            if ($hostname === '' || $instanceId === '' || isset($normalized[$hostname])) {
                throw new InvalidArgumentException('Task tmp cleanup targets must have unique hostname and instance_id values.');
            }
            $normalized[$hostname] = [
                'hostname' => $hostname,
                'instance_id' => $instanceId,
            ];
        }

        return array_values($normalized);
    }

    /** @return array<string, mixed>|null */
    private function readRequest(string $requestId): ?array
    {
        return $this->decode($this->readString($this->requestKey($requestId)));
    }

    /** @return array<string, mixed>|null */
    private function readResult(string $requestId, string $hostname): ?array
    {
        return $this->decode($this->connection()->hget($this->resultsKey($requestId), $hostname));
    }

    /** @return array<string, mixed>|null */
    private function decode(mixed $value): ?array
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL_STATUSES, true);
    }

    private function readString(string $key): ?string
    {
        $value = $this->connection()->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function connection()
    {
        $name = $this->config->get('app_stats.redis_connection');

        return $this->redis->connection(is_string($name) && $name !== '' ? $name : null);
    }

    private function executionTimeoutSeconds(): int
    {
        return max(1, (int) $this->config->get('swoole_task_tmp_cleaner.cleanup_execution_timeout_seconds', 900));
    }

    private function retentionSeconds(): int
    {
        return max(1, (int) $this->config->get('swoole_task_tmp_cleaner.cleanup_request_ttl_seconds', 3600));
    }

    private function leaseSeconds(): int
    {
        return max(1, (int) $this->config->get('swoole_task_tmp_cleaner.cleanup_lease_seconds', 30));
    }

    private function activeKey(): string
    {
        return (string) $this->config->get('app_stats.task_tmp_cleanup_active_key', 'app_stats:swoole_task_tmp:cleanup:active');
    }

    private function lastKey(): string
    {
        return (string) $this->config->get('app_stats.task_tmp_cleanup_last_key', 'app_stats:swoole_task_tmp:cleanup:last');
    }

    private function requestKey(string $requestId): string
    {
        return (string) $this->config->get('app_stats.task_tmp_cleanup_request_prefix', 'app_stats:swoole_task_tmp:cleanup:request:') . $requestId;
    }

    private function deadlineKey(string $requestId): string
    {
        return $this->deadlinePrefix() . $requestId;
    }

    private function deadlinePrefix(): string
    {
        return (string) $this->config->get(
            'app_stats.task_tmp_cleanup_deadline_prefix',
            'app_stats:swoole_task_tmp:cleanup:deadline:'
        );
    }

    private function resultsKey(string $requestId): string
    {
        return (string) $this->config->get('app_stats.task_tmp_cleanup_results_prefix', 'app_stats:swoole_task_tmp:cleanup:results:') . $requestId;
    }

    private function leaseKey(string $requestId, string $hostname, string $instanceId): string
    {
        return (string) $this->config->get('app_stats.task_tmp_cleanup_lease_prefix', 'app_stats:swoole_task_tmp:cleanup:lease:')
            . $requestId . ':' . $hostname . ':' . $instanceId;
    }
}
