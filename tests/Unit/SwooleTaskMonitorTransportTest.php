<?php

declare(strict_types=1);

namespace Mallto\Tool\Tests\Unit;

use Hhxsv5\LaravelS\Swoole\Task\Task;
use Mallto\Tool\Domain\SwooleTaskMonitor\SwooleTaskMonitor;
use Mallto\Tool\Domain\SwooleTaskMonitor\SwooleTaskMonitorStore;
use Tests\TestCase;

final class SwooleTaskMonitorTransportTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        config(['swoole_task_monitor.enabled' => false]);
    }

    public function test_custom_transport_result_is_returned_without_falling_back_to_laravels_dispatch(): void
    {
        $calls = 0;
        $task = new class extends Task {
            public function handle(): void {}
        };

        $accepted = SwooleTaskMonitor::deliver($task, ['coalesced_notification' => true], null,
            static function (Task $received) use (&$calls, $task): bool {
                self::assertSame($task, $received);
                ++$calls;
                return true;
            });
        $rejected = SwooleTaskMonitor::deliver($task, ['coalesced_notification' => true], null,
            static function (Task $received) use (&$calls, $task): bool {
                self::assertSame($task, $received);
                ++$calls;
                return false;
            });

        self::assertTrue($accepted);
        self::assertFalse($rejected);
        self::assertSame(2, $calls);
    }

    public function test_isolated_recorder_accounts_for_success_rejection_exception_and_drop(): void
    {
        config(['swoole_task_monitor.enabled' => true, 'swoole_task_monitor.mode' => 'summary',
            'swoole_task_monitor.trace_sample_rate' => 0]);
        $recorder = \Mockery::mock(SwooleTaskMonitorStore::class);
        $recorder->shouldReceive('recordSubmittedAndExpectedDeliveredSummary')->times(3);
        $recorder->shouldReceive('recordSummaryDeliveryFailed')->twice();
        $recorder->shouldReceive('recordDropped')->once();
        $task = new class extends Task { public function handle(): void {} };
        self::assertTrue(SwooleTaskMonitor::deliver($task, [], null, static fn() => true, $recorder));
        self::assertFalse(SwooleTaskMonitor::deliver($task, [], null, static fn() => false, $recorder));
        $exception = new \RuntimeException('transport failure');
        try {
            SwooleTaskMonitor::deliver($task, [], null, static fn() => throw $exception, $recorder);
            self::fail('Transport exception was swallowed');
        } catch (\RuntimeException $caught) {
            self::assertSame($exception, $caught);
        }
        SwooleTaskMonitor::recordDropped($task, 'coalesced_deliver_false', [], $recorder);
    }

    public function test_isolated_pipeline_failure_purges_only_its_own_manager(): void
    {
        config(['swoole_task_monitor.redis_connection' => 'task-monitor']);
        $connection = \Mockery::mock(\Illuminate\Redis\Connections\PhpRedisConnection::class);
        $connection->shouldReceive('client')->andReturn(new \Redis());
        $connection->shouldReceive('pipeline')->once()->andThrow(new \RuntimeException('pipeline failure'));
        $connection->shouldReceive('disconnect')->once();
        $connection->shouldReceive('expire')->twice()->andReturn(true);
        $manager = \Mockery::mock(\Illuminate\Redis\RedisManager::class);
        $manager->shouldReceive('connection')->with('task-monitor')->andReturn($connection);
        $manager->shouldReceive('purge')->with('task-monitor')->once();
        \Illuminate\Support\Facades\Redis::shouldReceive('purge')->never();
        $recorder = new SwooleTaskMonitorStore(redisManager: $manager);
        $this->expectExceptionMessage('pipeline failure');
        $recorder->recordSubmittedAndExpectedDeliveredSummary('isolated-test');
    }
}
