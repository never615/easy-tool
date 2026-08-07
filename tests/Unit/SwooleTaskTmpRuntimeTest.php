<?php

namespace Mallto\Tool\Tests\Unit;

use Closure;
use Illuminate\Config\Repository;
use Mallto\Tool\Domain\SwooleTaskTmp\SwooleTaskTmpCleanupRequestStore;
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

    /** @var Mockery\MockInterface&SwooleTaskTmpCleanupRequestStore */
    private SwooleTaskTmpCleanupRequestStore $requests;

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
                'backlog_retry_seconds' => 1,
                'delete_limit' => 100000,
            ],
        ]);
        $this->snapshots = new RuntimeRecordingSnapshotStore();
        $this->requests = Mockery::mock(SwooleTaskTmpCleanupRequestStore::class);
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
        $this->requests->shouldReceive('markRunning')->atLeast()->once();
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

    public function test_automatic_cleanup_runs_only_when_explicitly_enabled(): void
    {
        $this->config->set('swoole_task_tmp_cleaner.auto_cleanup_enabled', true);
        $path = $this->writeTaskFile('swoole.task.old', $this->now - 120);
        $this->requests->shouldReceive('claim')->zeroOrMoreTimes()->andReturnNull();

        $this->runUntil($this->now);

        $this->assertFileDoesNotExist($path);
        $cleanup = $this->snapshots->latest()['cleanup'];
        $this->assertNull($cleanup['request_id']);
        $this->assertSame('succeeded', $cleanup['status']);
    }

    public function test_manual_cleanup_repeats_limited_batches_and_reports_cumulative_progress(): void
    {
        $this->config->set('swoole_task_tmp_cleaner.delete_limit', 2);
        foreach (range(1, 5) as $index) {
            $this->writeTaskFile('swoole.task.'.$index, $this->now - 120);
        }
        $progress = [];
        $this->requests->shouldReceive('claim')->zeroOrMoreTimes()->andReturn(
            ['request_id' => 'request-1', 'deadline_at' => $this->now + 100],
            null
        );
        $this->requests->shouldReceive('markRunning')->atLeast()->times(3)->andReturnUsing(
            static function (string $requestId, string $hostname, array $payload) use (&$progress): void {
                $progress[] = $payload;
            }
        );
        $this->requests->shouldReceive('markFinished')->once()->with(
            'request-1',
            'pod-a',
            'succeeded',
            Mockery::on(static fn (array $result): bool => $result['deleted'] === 5 && $result['batches'] === 3)
        );

        $this->runUntil($this->now + 5);

        $this->assertSame([2, 4, 5], array_column($progress, 'deleted'));
        $this->assertSame([], glob($this->directory.'/swoole.task.*'));
    }

    public function test_stop_interrupts_manual_cleanup_marks_request_timed_out_and_forgets_snapshot(): void
    {
        foreach (range(1, 3) as $index) {
            $this->writeTaskFile('swoole.task.'.$index, $this->now - 120);
        }
        $continueCalls = 0;
        $this->requests->shouldReceive('claim')->once()->andReturn([
            'request_id' => 'request-1',
            'deadline_at' => $this->now + 100,
        ]);
        $this->requests->shouldReceive('markRunning')->atLeast()->once();
        $this->requests->shouldReceive('markFinished')->once()->with(
            'request-1',
            'pod-a',
            'timed_out',
            Mockery::on(static fn (array $result): bool => $result['error_type'] === 'stopped')
        );

        $this->runtime()->run(
            'pod-a',
            'pod-a:100:9',
            $this->directory,
            static function () use (&$continueCalls): bool {
                $continueCalls++;

                return $continueCalls < 3;
            },
            static function (int $seconds): void {
            }
        );

        $this->assertSame(1, $this->snapshots->forgetCount);
        $this->assertNotSame([], glob($this->directory.'/swoole.task.*'));
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
            fn (): int => $this->now
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
