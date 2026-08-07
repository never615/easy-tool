<?php

namespace Mallto\Tool\Tests\Unit;

use Closure;
use Illuminate\Config\Repository;
use Mallto\Tool\Domain\SwooleTaskTmp\SwooleTaskTmpCleanupRequestCoordinator;
use Mallto\Tool\Domain\SwooleTaskTmp\SwooleTaskTmpFileCleaner;
use Mallto\Tool\Domain\SwooleTaskTmp\SwooleTaskTmpFilesystemStats;
use Mallto\Tool\Domain\SwooleTaskTmp\SwooleTaskTmpInventoryScanner;
use Mallto\Tool\Domain\SwooleTaskTmp\SwooleTaskTmpRuntime;
use Mallto\Tool\Domain\SwooleTaskTmp\SwooleTaskTmpSnapshotStore;
use Mockery;
use PHPUnit\Framework\TestCase;

class SwooleTaskTmpRuntimeTest extends TestCase
{
    private string $directory;

    private int $now;

    private Repository $config;

    private RuntimeRecordingSnapshotStore $snapshots;

    private ?Closure $clock = null;

    /** @var Mockery\MockInterface&SwooleTaskTmpCleanupRequestCoordinator */
    private SwooleTaskTmpCleanupRequestCoordinator $requests;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/swoole-task-runtime-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
        $this->now = time();
        $this->config = new Repository([
            'swoole_task_tmp_cleaner' => [
                'auto_cleanup_enabled' => false,
                'stale_seconds' => 60,
                'filesystem_stats_interval_seconds' => 5,
                'inventory_interval_seconds' => 300,
                'command_poll_interval_seconds' => 1,
                'scan_interval_seconds' => 30,
                'backlog_retry_seconds' => 1,
                'delete_limit' => 100000,
                'cleanup_lease_seconds' => 30,
            ],
        ]);
        $this->snapshots = new RuntimeRecordingSnapshotStore();
        $this->requests = Mockery::mock(SwooleTaskTmpCleanupRequestCoordinator::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->removeTree($this->directory);

        parent::tearDown();
    }

    public function test_disabled_auto_cleanup_still_samples_and_executes_a_manual_request(): void
    {
        $path = $this->writeTaskFile('swoole.task.old', $this->now - 120);
        $this->requests->shouldReceive('claim')->zeroOrMoreTimes()->andReturn(
            ['request_id' => 'request-1', 'deadline_at' => $this->now + 100],
            null
        );
        $this->requests->shouldReceive('renew')->once()->andReturnTrue();
        $this->requests->shouldReceive('markRunning')->once();
        $this->requests->shouldReceive('markFinished')->once()->with(
            'request-1',
            'pod-a',
            'succeeded',
            Mockery::on(static fn (array $result): bool => $result['deleted'] === 1)
        );

        $this->runUntil($this->now + 1);

        $this->assertFileDoesNotExist($path);
        $this->assertNotEmpty($this->snapshots->stored);
        $this->assertSame(1, $this->snapshots->forgetCount);
    }

    public function test_disabled_auto_cleanup_never_deletes_without_a_manual_request(): void
    {
        $path = $this->writeTaskFile('swoole.task.old', $this->now - 120);
        $this->requests->shouldReceive('claim')->zeroOrMoreTimes()->andReturnNull();

        $this->runUntil($this->now + 2);

        $this->assertFileExists($path);
        $latest = $this->snapshots->latest();
        $this->assertFalse($latest['auto_cleanup_enabled']);
        $this->assertSame(1, $latest['inventory']['stale_files']);
    }

    public function test_capacity_and_inventory_use_independent_cadences(): void
    {
        $this->requests->shouldReceive('claim')->zeroOrMoreTimes()->andReturnNull();
        $startedAt = $this->now;

        $this->runUntil($startedAt + 10);

        $filesystemSamples = array_values(array_unique(array_column(
            array_column($this->snapshots->stored, 'payload'),
            'filesystem_sampled_at'
        )));
        $inventorySamples = array_values(array_unique(array_filter(array_column(
            array_column($this->snapshots->stored, 'payload'),
            'inventory_started_at'
        ), static fn ($value): bool => is_int($value))));

        $this->assertSame([$startedAt, $startedAt + 5, $startedAt + 10], $filesystemSamples);
        $this->assertSame([$startedAt], $inventorySamples);
    }

    public function test_automatic_cleanup_runs_at_scan_interval_not_every_second(): void
    {
        $this->config->set('swoole_task_tmp_cleaner.auto_cleanup_enabled', true);
        $this->writeTaskFile('swoole.task.old', $this->now - 120);
        $this->requests->shouldReceive('claim')->zeroOrMoreTimes()->andReturnNull();
        $startedAt = $this->now;

        $this->runUntil($startedAt + 31);

        $automaticStarts = array_values(array_unique(array_filter(array_map(
            static fn (array $snapshot): ?int => $snapshot['payload']['cleanup']['request_id'] === null
                ? $snapshot['payload']['cleanup']['started_at']
                : null,
            $this->snapshots->stored
        ), static fn ($value): bool => is_int($value))));

        $this->assertSame([$startedAt, $startedAt + 30], $automaticStarts);
    }

    public function test_limited_manual_batches_allow_capacity_refresh_between_retries(): void
    {
        $this->config->set('swoole_task_tmp_cleaner.delete_limit', 2);
        foreach (range(1, 6) as $index) {
            $this->writeTaskFile('swoole.task.'.$index, $this->now - 120);
        }
        $progress = [];
        $this->requests->shouldReceive('claim')->zeroOrMoreTimes()->andReturn(
            ['request_id' => 'request-1', 'deadline_at' => $this->now + 100],
            null
        );
        $this->requests->shouldReceive('renew')->times(7)->with(
            'request-1',
            'pod-a',
            'pod-a:100:9',
            Mockery::type('int')
        )->andReturnTrue();
        $this->requests->shouldReceive('markRunning')->times(4)->andReturnUsing(
            static function (string $requestId, string $hostname, array $payload) use (&$progress): void {
                $progress[] = $payload;
            }
        );
        $this->requests->shouldReceive('markFinished')->once()->with(
            'request-1',
            'pod-a',
            'succeeded',
            Mockery::on(static fn (array $result): bool => $result['deleted'] === 6 && $result['batches'] === 4)
        );
        $startedAt = $this->now;

        $this->runUntil($startedAt + 5);

        $filesystemSamples = array_values(array_unique(array_column(
            array_column($this->snapshots->stored, 'payload'),
            'filesystem_sampled_at'
        )));
        // Cleaner reaches its limit at the end of the third delete batch, so one final
        // empty batch confirms that no matching files remain.
        $this->assertSame([2, 4, 6, 6], array_column($progress, 'deleted'));
        $this->assertSame([$startedAt, $startedAt + 5], $filesystemSamples);
        $this->assertSame([], glob($this->directory.'/swoole.task.*'));
    }

    public function test_lost_manual_lease_stops_backlog_cleanup_even_when_automatic_cleanup_is_enabled(): void
    {
        $this->config->set('swoole_task_tmp_cleaner.delete_limit', 1);
        $this->config->set('swoole_task_tmp_cleaner.auto_cleanup_enabled', true);
        foreach (range(1, 2) as $index) {
            $this->writeTaskFile('swoole.task.'.$index, $this->now - 120);
        }
        $this->requests->shouldReceive('claim')->zeroOrMoreTimes()->andReturn(
            ['request_id' => 'request-1', 'deadline_at' => $this->now + 100],
            null
        );
        $this->requests->shouldReceive('renew')->times(2)->with(
            'request-1',
            'pod-a',
            'pod-a:100:9',
            Mockery::type('int')
        )->andReturn(true, false);
        $this->requests->shouldReceive('markRunning')->once();
        $this->requests->shouldReceive('markFinished')->never();

        $this->runUntil($this->now + 1);

        $this->assertCount(1, glob($this->directory.'/swoole.task.*'));
        $this->assertSame('lease_lost', $this->snapshots->latest()['cleanup']['error_type']);
    }

    public function test_batch_completion_rechecks_lease_before_writing_progress_or_falling_back_to_auto_cleanup(): void
    {
        $this->config->set('swoole_task_tmp_cleaner.delete_limit', 1);
        $this->config->set('swoole_task_tmp_cleaner.auto_cleanup_enabled', true);
        foreach (range(1, 2) as $index) {
            $this->writeTaskFile('swoole.task.'.$index, $this->now - 120);
        }
        $this->requests->shouldReceive('claim')->zeroOrMoreTimes()->andReturn(
            ['request_id' => 'request-1', 'deadline_at' => $this->now + 100],
            null
        );
        $this->requests->shouldReceive('renew')->once()->andReturnFalse();
        $this->requests->shouldReceive('markRunning')->never();
        $this->requests->shouldReceive('markFinished')->never();

        $this->runUntil($this->now + 1);

        $this->assertCount(1, glob($this->directory.'/swoole.task.*'));
        $this->assertSame('lease_lost', $this->snapshots->latest()['cleanup']['error_type']);
    }

    public function test_batch_heartbeat_lease_loss_does_not_fall_back_to_automatic_cleanup(): void
    {
        $this->config->set('swoole_task_tmp_cleaner.auto_cleanup_enabled', true);
        $this->config->set('swoole_task_tmp_cleaner.cleanup_lease_seconds', 2);
        foreach (range(1, 4) as $index) {
            $this->writeTaskFile('swoole.task.'.$index, $this->now - 120);
        }
        $clockCalls = 0;
        $this->clock = function () use (&$clockCalls): int {
            return $this->now + $clockCalls++;
        };
        $this->requests->shouldReceive('claim')->once()->andReturn([
            'request_id' => 'request-1',
            'deadline_at' => $this->now + 1000,
        ]);
        $this->requests->shouldReceive('renew')->times(2)->andReturn(true, false);
        $this->requests->shouldReceive('markRunning')->never();
        $this->requests->shouldReceive('markFinished')->never();
        $running = true;

        $this->runtime()->run(
            'pod-a',
            'pod-a:100:9',
            $this->directory,
            static function () use (&$running): bool {
                return $running;
            },
            static function (int $seconds) use (&$running): void {
                $running = false;
            }
        );

        $this->assertCount(3, glob($this->directory.'/swoole.task.*'));
        $this->assertSame('lease_lost', $this->snapshots->latest()['cleanup']['error_type']);
    }

    public function test_single_manual_batch_renews_the_lease_during_long_file_iteration(): void
    {
        $this->config->set('swoole_task_tmp_cleaner.cleanup_lease_seconds', 2);
        foreach (range(1, 4) as $index) {
            $this->writeTaskFile('swoole.task.'.$index, $this->now - 120);
        }
        $clockCalls = 0;
        $this->clock = function () use (&$clockCalls): int {
            return $this->now + $clockCalls++;
        };
        $this->requests->shouldReceive('claim')->once()->andReturn([
            'request_id' => 'request-1',
            'deadline_at' => $this->now + 1000,
        ]);
        $this->requests->shouldReceive('renew')->atLeast()->once()->andReturnTrue();
        $this->requests->shouldReceive('markRunning')->once();
        $this->requests->shouldReceive('markFinished')->once()->with(
            'request-1',
            'pod-a',
            'succeeded',
            Mockery::type('array')
        );
        $running = true;

        $this->runtime()->run(
            'pod-a',
            'pod-a:100:9',
            $this->directory,
            static function () use (&$running): bool {
                return $running;
            },
            static function (int $seconds) use (&$running): void {
                $running = false;
            }
        );

        $this->assertSame([], glob($this->directory.'/swoole.task.*'));
    }

    public function test_stop_marks_manual_request_as_stopped_without_reporting_deadline(): void
    {
        $this->config->set('swoole_task_tmp_cleaner.delete_limit', 1);
        foreach (range(1, 2) as $index) {
            $this->writeTaskFile('swoole.task.'.$index, $this->now - 120);
        }
        $this->requests->shouldReceive('claim')->once()->andReturn([
            'request_id' => 'request-1',
            'deadline_at' => $this->now + 100,
        ]);
        $this->requests->shouldReceive('renew')->once()->andReturnTrue();
        $this->requests->shouldReceive('markRunning')->once();
        $this->requests->shouldReceive('markFinished')->once()->with(
            'request-1',
            'pod-a',
            'timed_out',
            Mockery::on(static fn (array $result): bool => $result['error_type'] === 'stopped')
        );

        $this->runUntil($this->now);

        $this->assertSame(1, $this->snapshots->forgetCount);
    }

    public function test_expired_manual_deadline_is_reported_distinctly(): void
    {
        $this->requests->shouldReceive('claim')->once()->andReturn([
            'request_id' => 'request-1',
            'deadline_at' => $this->now,
        ]);
        $this->requests->shouldReceive('markRunning')->never();
        $this->requests->shouldReceive('markFinished')->once()->with(
            'request-1',
            'pod-a',
            'timed_out',
            Mockery::on(static fn (array $result): bool => $result['error_type'] === 'deadline_exceeded')
        );

        $this->runUntil($this->now);

        $this->assertSame(1, $this->snapshots->forgetCount);
    }

    private function runtime(): SwooleTaskTmpRuntime
    {
        return new SwooleTaskTmpRuntime(
            $this->config,
            new SwooleTaskTmpFilesystemStats(),
            new SwooleTaskTmpInventoryScanner(),
            new SwooleTaskTmpFileCleaner(),
            $this->snapshots,
            $this->requests,
            $this->clock ?? fn (): int => $this->now
        );
    }

    private function runUntil(int $stopAt): void
    {
        $this->runtime()->run(
            'pod-a',
            'pod-a:100:9',
            $this->directory,
            fn (): bool => $this->now <= $stopAt,
            function (int $seconds): void {
                $this->now += max(1, $seconds);
            }
        );
    }

    private function writeTaskFile(string $name, int $mtime): string
    {
        $path = $this->directory.'/'.$name;
        file_put_contents($path, $name);
        touch($path, $mtime);

        return $path;
    }

    private function removeTree(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
            @unlink($entry->getPathname());
        }
        @rmdir($directory);
    }
}

final class RuntimeRecordingSnapshotStore extends SwooleTaskTmpSnapshotStore
{
    /** @var list<array{hostname: string, payload: array<string, mixed>, sampled_at: int}> */
    public array $stored = [];

    public int $forgetCount = 0;

    public function __construct()
    {
    }

    public function store(string $hostname, array $payload, int $sampledAt): void
    {
        $this->stored[] = [
            'hostname' => $hostname,
            'payload' => $payload,
            'sampled_at' => $sampledAt,
        ];
    }

    public function forget(string $hostname): void
    {
        $this->forgetCount++;
    }

    /** @return array<string, mixed> */
    public function latest(): array
    {
        return $this->stored[array_key_last($this->stored)]['payload'];
    }
}
