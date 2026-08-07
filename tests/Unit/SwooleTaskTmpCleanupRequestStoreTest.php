<?php

namespace Mallto\Tool\Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use InvalidArgumentException;
use Mallto\Tool\Domain\SwooleTaskTmp\SwooleTaskTmpCleanupRequestStore;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SwooleTaskTmpCleanupRequestStoreTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_create_freezes_targets_and_reuses_an_unexpired_active_request(): void
    {
        [$store, $redis] = $this->store();

        $first = $store->create([
            ['hostname' => 'pod-a', 'instance_id' => 'pod-a:100:9'],
            ['hostname' => 'pod-b', 'instance_id' => 'pod-b:100:10'],
        ], 7, 200);
        $second = $store->create([
            ['hostname' => 'pod-c', 'instance_id' => 'pod-c:100:11'],
        ], 8, 201);

        $this->assertTrue($first['created']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $first['request_id']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['request_id'], $second['request_id']);

        $request = $redis->decodedString($this->requestKey($first['request_id']));
        $this->assertSame(7, $request['requested_by']);
        $this->assertSame(1100, $request['deadline_at']);
        $this->assertSame(['pod-a', 'pod-b'], array_column($request['targets'], 'hostname'));
        $this->assertSame(3600, $redis->ttlSetByEval[$this->requestKey($first['request_id'])]);
        $this->assertSame(3600, $redis->ttlSetByEval[$this->resultsKey($first['request_id'])]);
    }

    public function test_create_atomically_replaces_an_expired_active_request_and_keeps_old_history(): void
    {
        [$store, $redis] = $this->store();
        $first = $store->create($this->targets(), 7, 200);

        $second = $store->create([
            ['hostname' => 'pod-b', 'instance_id' => 'pod-b:101:10'],
        ], 8, 1100);

        $this->assertTrue($second['created']);
        $this->assertNotSame($first['request_id'], $second['request_id']);
        $this->assertSame($second['request_id'], $redis->strings[$this->activeKey()]);
        $this->assertArrayHasKey($this->requestKey($first['request_id']), $redis->strings);
        $this->assertArrayHasKey($this->resultsKey($first['request_id']), $redis->hashes);
        $this->assertSame(2, $redis->createEvalCount);
    }

    public function test_claim_requires_hostname_and_instance_id_and_retries_after_a_stale_lease(): void
    {
        [$store, $redis] = $this->store(5);
        $request = $store->create($this->targets(), 7, 200);

        $this->assertNull($store->claim('pod-a', 'pod-a:101:11', 205));
        $claim = $store->claim('pod-a', 'pod-a:100:9', 205);
        $this->assertSame($request['request_id'], $claim['request_id']);
        $this->assertSame(1100, $claim['deadline_at']);
        $this->assertNull($store->claim('pod-a', 'pod-a:100:9', 206));

        // Redis 的租约按服务端时钟过期；测试替身显式推进该时钟后再模拟下一次 Pod 轮询。
        $redis->now = 210;
        $retry = $store->claim('pod-a', 'pod-a:100:9', 210);
        $this->assertSame($request['request_id'], $retry['request_id']);
        $this->assertSame(5, $redis->lastSetExpirySeconds);
    }

    #[DataProvider('terminalStatusProvider')]
    public function test_terminal_results_cannot_be_claimed_again(string $status): void
    {
        [$store] = $this->store();
        $request = $store->create($this->targets(), 7, 200);
        $store->markFinished($request['request_id'], 'pod-a', $status, ['finished_at' => 220]);

        $this->assertNull($store->claim('pod-a', 'pod-a:100:9', 221));
    }

    public static function terminalStatusProvider(): array
    {
        return [
            ['succeeded'],
            ['failed'],
            ['timed_out'],
        ];
    }

    public function test_mark_running_uses_only_the_fixed_running_status(): void
    {
        [$store, $redis] = $this->store();
        $request = $store->create($this->targets(), 7, 200);

        $store->markRunning($request['request_id'], 'pod-a', [
            'status' => 'succeeded',
            'scanned' => 100,
        ]);

        $result = $redis->decodedHashField($this->resultsKey($request['request_id']), 'pod-a');
        $this->assertSame('running', $result['status']);
        $this->assertSame(100, $result['scanned']);
    }

    public function test_unknown_targets_cannot_create_or_change_a_result(): void
    {
        [$store, $redis] = $this->store();
        $request = $store->create($this->targets(), 7, 200);
        $beforeWrites = $redis->writeCount;

        $store->markRunning($request['request_id'], 'pod-unknown', ['scanned' => 100]);
        $store->markFinished($request['request_id'], 'pod-unknown', 'succeeded', []);

        $this->assertSame($beforeWrites, $redis->writeCount);
        $this->assertArrayNotHasKey('pod-unknown', $redis->hashes[$this->resultsKey($request['request_id'])]);
    }

    public function test_create_rejects_empty_or_duplicate_targets(): void
    {
        [$store] = $this->store();

        try {
            $store->create([], 7, 200);
            $this->fail('Expected empty targets to be rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        $store->create([
            ['hostname' => 'pod-a', 'instance_id' => 'pod-a:100:9'],
            ['hostname' => 'pod-a', 'instance_id' => 'pod-a:100:10'],
        ], 7, 200);
    }

    public function test_mark_finished_rejects_non_terminal_statuses(): void
    {
        [$store] = $this->store();

        $this->expectException(InvalidArgumentException::class);
        $store->markFinished('request-1', 'pod-a', 'running', []);
    }

    public function test_all_terminal_results_clear_only_the_matching_active_pointer_and_write_last(): void
    {
        [$store, $redis] = $this->store();
        $request = $store->create($this->targets(), 7, 200);

        $store->markFinished($request['request_id'], 'pod-a', 'succeeded', []);

        $this->assertArrayNotHasKey($this->activeKey(), $redis->strings);
        $this->assertSame($request['request_id'], $redis->strings[$this->lastKey()]);
        $this->assertArrayHasKey($this->requestKey($request['request_id']), $redis->strings);
        $this->assertArrayHasKey($this->resultsKey($request['request_id']), $redis->hashes);
        $this->assertSame(3600, $redis->ttlSetByEval[$this->lastKey()]);
    }

    public function test_finishing_an_old_request_does_not_clear_a_new_active_request_or_replace_last(): void
    {
        [$store, $redis] = $this->store();
        $old = $store->create($this->targets(), 7, 200);
        $new = $store->create($this->targets(), 8, 1100);
        $redis->strings[$this->lastKey()] = 'previous-request';

        $store->markFinished($old['request_id'], 'pod-a', 'succeeded', []);

        $this->assertSame($new['request_id'], $redis->strings[$this->activeKey()]);
        $this->assertSame('previous-request', $redis->strings[$this->lastKey()]);
    }

    #[DataProvider('aggregateStatusProvider')]
    public function test_current_status_aggregates_fixed_target_results(array $statuses, string $expected): void
    {
        [$store, $redis] = $this->store();
        $requestId = $redis->seedRequest($this->targets('pod-a', 'pod-b'), 200, 1100, $statuses, true);

        $beforeWrites = $redis->writeCount;
        $status = $store->currentStatus(250);

        $this->assertSame($requestId, $status['request_id']);
        $this->assertSame($expected, $status['status']);
        $this->assertSame($statuses, array_column($status['targets'], 'status'));
        $this->assertSame($beforeWrites, $redis->writeCount, 'GET status rendering must not write Redis.');
    }

    public static function aggregateStatusProvider(): array
    {
        return [
            'all succeeded' => [['succeeded', 'succeeded'], 'succeeded'],
            'success and failure' => [['succeeded', 'failed'], 'partial_failed'],
            'success and timeout' => [['succeeded', 'timed_out'], 'partial_failed'],
            'all timed out' => [['timed_out', 'timed_out'], 'timed_out'],
            'failure and timeout' => [['failed', 'timed_out'], 'failed'],
            'all failed' => [['failed', 'failed'], 'failed'],
            'all pending' => [['pending', 'pending'], 'pending'],
            'one running' => [['running', 'pending'], 'running'],
        ];
    }

    public function test_current_status_maps_non_terminal_targets_to_timed_out_in_memory_only(): void
    {
        [$store, $redis] = $this->store();
        $requestId = $redis->seedRequest(
            $this->targets('pod-a', 'pod-b'),
            200,
            1100,
            ['running', 'pending'],
            true
        );
        $beforeResults = $redis->hashes[$this->resultsKey($requestId)];
        $beforeWrites = $redis->writeCount;

        $status = $store->currentStatus(1100);

        $this->assertSame('timed_out', $status['status']);
        $this->assertSame(['timed_out', 'timed_out'], array_column($status['targets'], 'status'));
        $this->assertSame($beforeResults, $redis->hashes[$this->resultsKey($requestId)]);
        $this->assertSame($beforeWrites, $redis->writeCount);
    }

    public function test_current_status_prefers_active_then_falls_back_to_last(): void
    {
        [$store, $redis] = $this->store();
        $last = $redis->seedRequest($this->targets('pod-old'), 100, 1000, ['succeeded'], false);
        $redis->strings[$this->lastKey()] = $last;
        $active = $redis->seedRequest($this->targets('pod-new'), 200, 1100, ['pending'], true);

        $this->assertSame($active, $store->currentStatus(250)['request_id']);

        unset($redis->strings[$this->activeKey()]);
        $this->assertSame($last, $store->currentStatus(250)['request_id']);
    }

    public function test_request_state_expires_after_the_configured_retention(): void
    {
        [$store, $redis] = $this->store();
        $request = $store->create($this->targets(), 7, 200);

        $redis->now = 3799;
        $this->assertNotNull($store->currentStatus(3799));
        $redis->now = 3800;
        $this->assertNull($store->currentStatus(3800));
    }

    /**
     * @return array{SwooleTaskTmpCleanupRequestStore, CleanupRequestFakeRedisConnection}
     */
    private function store(int $leaseSeconds = 30): array
    {
        $connection = new CleanupRequestFakeRedisConnection();
        $redis = Mockery::mock(RedisFactory::class);
        $redis->shouldReceive('connection')->zeroOrMoreTimes()->with(null)->andReturn($connection);

        $config = new Repository([
            'app_stats' => [
                'redis_connection' => null,
                'task_tmp_cleanup_active_key' => $this->activeKey(),
                'task_tmp_cleanup_last_key' => $this->lastKey(),
                'task_tmp_cleanup_request_prefix' => 'app_stats:swoole_task_tmp:cleanup:request:',
                'task_tmp_cleanup_deadline_prefix' => 'app_stats:swoole_task_tmp:cleanup:deadline:',
                'task_tmp_cleanup_results_prefix' => 'app_stats:swoole_task_tmp:cleanup:results:',
                'task_tmp_cleanup_lease_prefix' => 'app_stats:swoole_task_tmp:cleanup:lease:',
            ],
            'swoole_task_tmp_cleaner' => [
                'cleanup_execution_timeout_seconds' => 900,
                'cleanup_request_ttl_seconds' => 3600,
                'cleanup_lease_seconds' => $leaseSeconds,
            ],
        ]);

        return [new SwooleTaskTmpCleanupRequestStore($redis, $config), $connection];
    }

    /**
     * @return list<array{hostname: string, instance_id: string}>
     */
    private function targets(string ...$hostnames): array
    {
        $hostnames = $hostnames === [] ? ['pod-a'] : $hostnames;

        return array_map(
            static fn (string $hostname): array => [
                'hostname' => $hostname,
                'instance_id' => $hostname . ':100:9',
            ],
            $hostnames
        );
    }

    private function activeKey(): string
    {
        return 'app_stats:swoole_task_tmp:cleanup:active';
    }

    private function lastKey(): string
    {
        return 'app_stats:swoole_task_tmp:cleanup:last';
    }

    private function requestKey(string $requestId): string
    {
        return 'app_stats:swoole_task_tmp:cleanup:request:' . $requestId;
    }

    private function resultsKey(string $requestId): string
    {
        return 'app_stats:swoole_task_tmp:cleanup:results:' . $requestId;
    }
}

/**
 * 仅模拟本协议用到的固定 Redis 命令；RedisFactory 本身仍沿用项目现有 Mockery 模式。
 */
final class CleanupRequestFakeRedisConnection
{
    /** @var array<string, string> */
    public array $strings = [];

    /** @var array<string, array<string, string>> */
    public array $hashes = [];

    /** @var array<string, int> */
    public array $expiresAt = [];

    /** @var array<string, int> */
    public array $ttlSetByEval = [];

    public int $createEvalCount = 0;

    public int $now = 0;

    public int $writeCount = 0;

    public ?int $lastSetExpirySeconds = null;

    public function eval(string $script, int $numberOfKeys, mixed ...$arguments): array|int
    {
        if ($numberOfKeys === 4) {
            $this->createEvalCount++;

            return $this->evalCreate($numberOfKeys, $arguments);
        }

        if ($numberOfKeys === 5) {
            return $this->evalFinish($numberOfKeys, $arguments);
        }

        throw new InvalidArgumentException('Unexpected Lua script.');
    }

    public function get(string $key): string|false
    {
        $this->expireIfNeeded($key);

        return $this->strings[$key] ?? false;
    }

    public function set(string $key, string $value, mixed ...$options): bool
    {
        $expirySeconds = null;
        $onlyIfAbsent = false;
        for ($index = 0; $index < count($options); $index++) {
            $option = strtoupper((string) $options[$index]);
            if ($option === 'EX') {
                $expirySeconds = (int) ($options[++$index] ?? 0);
            } elseif ($option === 'NX') {
                $onlyIfAbsent = true;
            }
        }

        $this->expireIfNeeded($key);
        if ($onlyIfAbsent && array_key_exists($key, $this->strings)) {
            return false;
        }

        $this->strings[$key] = $value;
        $this->writeCount++;
        $this->lastSetExpirySeconds = $expirySeconds;
        if ($expirySeconds !== null) {
            $this->expiresAt[$key] = $this->now + $expirySeconds;
        }

        return true;
    }

    public function hget(string $key, string $field): string|false
    {
        $this->expireIfNeeded($key);

        return $this->hashes[$key][$field] ?? false;
    }

    /** @return array<string, string> */
    public function hgetall(string $key): array
    {
        $this->expireIfNeeded($key);

        return $this->hashes[$key] ?? [];
    }

    public function hset(string $key, string $field, string $value): int
    {
        $this->hashes[$key][$field] = $value;
        $this->writeCount++;

        return 1;
    }

    public function expire(string $key, int $seconds): bool
    {
        $this->expiresAt[$key] = $this->now + $seconds;
        $this->writeCount++;

        return true;
    }

    /**
     * @param list<array{hostname: string, instance_id: string}> $targets
     * @param list<string> $statuses
     */
    public function seedRequest(array $targets, int $createdAt, int $deadlineAt, array $statuses, bool $active): string
    {
        $requestId = bin2hex(random_bytes(16));
        $requestKey = 'app_stats:swoole_task_tmp:cleanup:request:' . $requestId;
        $deadlineKey = 'app_stats:swoole_task_tmp:cleanup:deadline:' . $requestId;
        $resultsKey = 'app_stats:swoole_task_tmp:cleanup:results:' . $requestId;
        $this->strings[$requestKey] = json_encode([
            'request_id' => $requestId,
            'requested_by' => 7,
            'created_at' => $createdAt,
            'deadline_at' => $deadlineAt,
            'targets' => $targets,
        ], JSON_THROW_ON_ERROR);
        $this->strings[$deadlineKey] = (string) $deadlineAt;
        foreach ($targets as $index => $target) {
            $this->hashes[$resultsKey][$target['hostname']] = json_encode([
                'status' => $statuses[$index],
                'updated_at' => $createdAt,
            ], JSON_THROW_ON_ERROR);
        }
        if ($active) {
            $this->strings['app_stats:swoole_task_tmp:cleanup:active'] = $requestId;
        }

        return $requestId;
    }

    /** @return array<string, mixed> */
    public function decodedString(string $key): array
    {
        return json_decode($this->strings[$key], true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public function decodedHashField(string $key, string $field): array
    {
        return json_decode($this->hashes[$key][$field], true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param list<mixed> $arguments */
    private function evalCreate(int $numberOfKeys, array $arguments): array
    {
        self::assertCountAtLeast(11, $arguments);
        if ($numberOfKeys !== 4) {
            throw new InvalidArgumentException('Create script key count mismatch.');
        }

        [$activeKey, $requestKey, $deadlineKey, $resultsKey] = array_slice($arguments, 0, 4);
        [$requestId, $requestJson, $now, $deadlineAt, $retention, $deadlinePrefix] = array_slice($arguments, 4, 6);
        $this->now = (int) $now;
        $activeRequestId = $this->get((string) $activeKey);
        if ($activeRequestId !== false) {
            $activeDeadline = $this->get((string) $deadlinePrefix . $activeRequestId);
            if ($activeDeadline !== false && (int) $activeDeadline > (int) $now) {
                return [0, $activeRequestId];
            }
        }

        $this->strings[(string) $activeKey] = (string) $requestId;
        $this->strings[(string) $requestKey] = (string) $requestJson;
        $this->strings[(string) $deadlineKey] = (string) $deadlineAt;
        for ($index = 10; $index + 1 < count($arguments); $index += 2) {
            $this->hashes[(string) $resultsKey][(string) $arguments[$index]] = (string) $arguments[$index + 1];
        }
        foreach ([$activeKey, $requestKey, $deadlineKey, $resultsKey] as $key) {
            $this->expiresAt[(string) $key] = $this->now + (int) $retention;
            $this->ttlSetByEval[(string) $key] = (int) $retention;
        }
        $this->writeCount++;

        return [1, $requestId];
    }

    /** @param list<mixed> $arguments */
    private function evalFinish(int $numberOfKeys, array $arguments): int
    {
        if ($numberOfKeys !== 5) {
            throw new InvalidArgumentException('Finish script key count mismatch.');
        }

        [$activeKey, $requestKey, $deadlineKey, $resultsKey, $lastKey] = array_slice($arguments, 0, 5);
        [$requestId, $hostname, $resultJson, $retention] = array_slice($arguments, 5, 4);
        if (! isset($this->strings[(string) $requestKey], $this->hashes[(string) $resultsKey][(string) $hostname])) {
            return 0;
        }

        $existing = $this->decodedHashField((string) $resultsKey, (string) $hostname);
        if (in_array($existing['status'] ?? null, ['succeeded', 'failed', 'timed_out'], true)) {
            return 0;
        }

        $this->hashes[(string) $resultsKey][(string) $hostname] = (string) $resultJson;
        $allTerminal = true;
        foreach ($this->hashes[(string) $resultsKey] as $json) {
            $result = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (! in_array($result['status'] ?? null, ['succeeded', 'failed', 'timed_out'], true)) {
                $allTerminal = false;
                break;
            }
        }

        if ($allTerminal && ($this->get((string) $activeKey) === (string) $requestId)) {
            unset($this->strings[(string) $activeKey], $this->expiresAt[(string) $activeKey]);
            $this->strings[(string) $lastKey] = (string) $requestId;
            foreach ([$requestKey, $deadlineKey, $resultsKey, $lastKey] as $key) {
                $this->expiresAt[(string) $key] = $this->now + (int) $retention;
                $this->ttlSetByEval[(string) $key] = (int) $retention;
            }
        }
        $this->writeCount++;

        return $allTerminal ? 2 : 1;
    }

    private function expireIfNeeded(string $key): void
    {
        if (($this->expiresAt[$key] ?? PHP_INT_MAX) > $this->now) {
            return;
        }

        unset($this->strings[$key], $this->hashes[$key], $this->expiresAt[$key]);
    }

    private static function assertCountAtLeast(int $minimum, array $values): void
    {
        if (count($values) < $minimum) {
            throw new InvalidArgumentException('Lua argument count mismatch.');
        }
    }
}
