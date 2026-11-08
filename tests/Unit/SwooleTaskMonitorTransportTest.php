<?php

declare(strict_types=1);

namespace Mallto\Tool\Tests\Unit;

use Hhxsv5\LaravelS\Swoole\Task\Task;
use Mallto\Tool\Domain\SwooleTaskMonitor\SwooleTaskMonitor;
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
}
