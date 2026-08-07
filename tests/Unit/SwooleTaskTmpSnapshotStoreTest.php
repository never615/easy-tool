<?php

namespace Mallto\Tool\Tests\Unit;

use Closure;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Mallto\Tool\Domain\SwooleTaskTmp\SwooleTaskTmpSnapshotStore;
use Mockery;
use PHPUnit\Framework\TestCase;

class SwooleTaskTmpSnapshotStoreTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_store_uses_its_own_hash_and_zset_in_one_pipeline(): void
    {
        $pipe = Mockery::mock();
        $pipe->shouldReceive('hset')
            ->once()
            ->with(
                'app_stats:swoole_task_tmp:pods',
                'pod-a',
                Mockery::on(function ($json): bool {
                    $payload = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);

                    return $payload['instance_id'] === 'pod-a:100:9'
                        && $payload['inventory']['stale_files'] === 20
                        && ! array_key_exists('staleFiles', $payload['inventory']);
                })
            );
        $pipe->shouldReceive('zadd')
            ->once()
            ->with('app_stats:swoole_task_tmp:pods:last_seen', 200, 'pod-a');

        $store = $this->storeWithPipeline($pipe);

        $store->store('pod-a', [
            'instance_id' => 'pod-a:100:9',
            'inventory' => [
                'staleFiles' => 20,
                'matchingFiles' => 30,
            ],
        ], 200);

        $this->addToAssertionCount(1);
    }

    public function test_read_all_uses_one_hash_and_one_zset_read_and_keeps_only_fresh_arrays(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('hgetall')
            ->once()
            ->with('app_stats:swoole_task_tmp:pods')
            ->andReturn([
                'pod-a' => json_encode([
                    'instance_id' => 'pod-a:100:9',
                    'filesystem' => ['used_percent' => 95.5],
                    'inventory' => ['staleFiles' => 20],
                ], JSON_THROW_ON_ERROR),
                'pod-old' => json_encode(['instance_id' => 'pod-old:100:8'], JSON_THROW_ON_ERROR),
                'broken' => '{',
                'scalar' => '"not-an-array"',
            ]);
        $connection->shouldReceive('zrange')
            ->once()
            ->with('app_stats:swoole_task_tmp:pods:last_seen', 0, -1, ['withscores' => true])
            ->andReturn([
                'pod-a' => 195,
                'pod-old' => 100,
                'broken' => 199,
                'scalar' => 199,
            ]);
        $connection->shouldNotReceive('keys');
        $connection->shouldNotReceive('scan');

        $store = $this->store($connection, 200);

        $snapshots = $store->readAll();

        $this->assertSame(['pod-a'], array_keys($snapshots));
        $this->assertSame('pod-a', $snapshots['pod-a']['hostname']);
        $this->assertSame('pod-a:100:9', $snapshots['pod-a']['instance_id']);
        $this->assertSame(195, $snapshots['pod-a']['updated_at']);
        $this->assertSame(5, $snapshots['pod-a']['sample_age_seconds']);
        $this->assertSame(95.5, $snapshots['pod-a']['filesystem']['used_percent']);
        $this->assertSame(20, $snapshots['pod-a']['inventory']['stale_files']);
        $this->assertArrayNotHasKey('staleFiles', $snapshots['pod-a']['inventory']);
    }

    public function test_read_all_rejects_a_hash_value_without_its_zset_timestamp(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('hgetall')
            ->once()
            ->with('app_stats:swoole_task_tmp:pods')
            ->andReturn([
                'pod-a' => json_encode([
                    'instance_id' => 'pod-a:100:9',
                    'updated_at' => 195,
                    'inventory' => ['stale_files' => 20],
                ], JSON_THROW_ON_ERROR),
            ]);
        $connection->shouldReceive('zrange')
            ->once()
            ->with('app_stats:swoole_task_tmp:pods:last_seen', 0, -1, ['withscores' => true])
            ->andReturn([]);
        $connection->shouldNotReceive('keys');
        $connection->shouldNotReceive('scan');

        $this->assertSame([], $this->store($connection, 200)->readAll());
    }

    public function test_forget_removes_one_hostname_from_task_tmp_hash_and_index_in_one_pipeline(): void
    {
        $pipe = Mockery::mock();
        $pipe->shouldReceive('hdel')->once()->with('app_stats:swoole_task_tmp:pods', 'pod-a');
        $pipe->shouldReceive('zrem')->once()->with('app_stats:swoole_task_tmp:pods:last_seen', 'pod-a');

        $store = $this->storeWithPipeline($pipe);
        $store->forget('pod-a');

        $this->addToAssertionCount(1);
    }

    private function storeWithPipeline($pipe): SwooleTaskTmpSnapshotStore
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('pipeline')
            ->once()
            ->andReturnUsing(function (Closure $callback) use ($pipe): void {
                $callback($pipe);
            });
        $connection->shouldNotReceive('keys');
        $connection->shouldNotReceive('scan');

        return $this->store($connection, 200);
    }

    private function store($connection, int $now): SwooleTaskTmpSnapshotStore
    {
        $redis = Mockery::mock(RedisFactory::class);
        $redis->shouldReceive('connection')->once()->with(null)->andReturn($connection);

        return new SwooleTaskTmpSnapshotStore(
            $redis,
            new Repository([
                'app_stats' => [
                    'redis_connection' => null,
                    'task_tmp_pods_hash_key' => 'app_stats:swoole_task_tmp:pods',
                    'task_tmp_pods_last_seen_key' => 'app_stats:swoole_task_tmp:pods:last_seen',
                    'task_tmp_snapshot_stale_seconds' => 60,
                ],
            ]),
            static fn (): int => $now
        );
    }
}
