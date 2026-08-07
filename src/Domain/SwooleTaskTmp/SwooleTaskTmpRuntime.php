<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\SwooleTaskTmp;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

/**
 * 每个 Pod 的 task_tmpdir 常驻运行时。
 *
 * 监控快照、手动清理命令和可选的自动清理都在本地进程内执行，HTTP 请求不会直接访问本地目录。
 */
final class SwooleTaskTmpRuntime
{
    private readonly Closure $clock;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly SwooleTaskTmpFilesystemStats $filesystemStats,
        private readonly SwooleTaskTmpInventoryScanner $inventoryScanner,
        private readonly SwooleTaskTmpFileCleaner $cleaner,
        private readonly SwooleTaskTmpSnapshotStore $snapshots,
        private readonly SwooleTaskTmpCleanupRequestStore $requests,
        ?Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param callable(): bool $shouldContinue
     * @param callable(int): void $sleep
     */
    public function run(
        string $hostname,
        string $instanceId,
        string $directory,
        callable $shouldContinue,
        callable $sleep
    ): void {
        $settings = $this->settings();
        $nextFilesystemSampleAt = 0;
        $nextInventoryAt = 0;
        $filesystem = $this->emptyFilesystem();
        $filesystemSampledAt = null;
        $inventory = $this->emptyInventory();
        $inventoryStartedAt = null;
        $cleanup = $this->emptyCleanup();

        try {
            while ($shouldContinue()) {
                $now = $this->now();

                if ($now >= $nextFilesystemSampleAt) {
                    $filesystem = $this->filesystemStats->read($directory);
                    $filesystemSampledAt = $now;
                    $nextFilesystemSampleAt = $now + $settings['filesystem_stats_interval_seconds'];
                }

                if ($now >= $nextInventoryAt) {
                    $inventoryStartedAt = $now;
                    $inventory = $this->inventoryPayload($this->inventoryScanner->scan(
                        $directory,
                        $now - $settings['stale_seconds'],
                        $shouldContinue
                    ), $inventoryStartedAt, $this->now());
                    $nextInventoryAt = $now + $settings['inventory_interval_seconds'];
                }

                $command = $this->requests->claim($hostname, $instanceId, $now);
                if ($command !== null) {
                    $cleanup = $this->runManualCleanup(
                        $command,
                        $hostname,
                        $directory,
                        $settings,
                        $shouldContinue,
                        $sleep
                    );
                } elseif ($settings['auto_cleanup_enabled']) {
                    $cleanup = $this->runAutomaticCleanup(
                        $directory,
                        $settings,
                        $shouldContinue,
                        $sleep
                    );
                }

                $this->snapshots->store($hostname, [
                    'instance_id' => $instanceId,
                    'path' => $directory,
                    'process_enabled' => true,
                    'auto_cleanup_enabled' => $settings['auto_cleanup_enabled'],
                    'filesystem' => $filesystem,
                    'filesystem_sampled_at' => $filesystemSampledAt,
                    'inventory' => $inventory,
                    'inventory_started_at' => $inventoryStartedAt,
                    'cleanup' => $cleanup,
                ], $this->now());

                $sleep($settings['command_poll_interval_seconds']);
            }
        } finally {
            $this->snapshots->forget($hostname);
        }
    }

    /**
     * @param array{request_id: string, deadline_at: int} $command
     * @param array<string, int|bool> $settings
     * @param callable(): bool $shouldContinue
     * @param callable(int): void $sleep
     * @return array<string, int|string|null>
     */
    private function runManualCleanup(
        array $command,
        string $hostname,
        string $directory,
        array $settings,
        callable $shouldContinue,
        callable $sleep
    ): array {
        $requestId = $command['request_id'];
        $deadlineAt = max(0, (int) $command['deadline_at']);
        $startedAt = $this->now();
        $cutoffTimestamp = $startedAt - $settings['stale_seconds'];
        $progress = $this->emptyCleanup();
        $progress['request_id'] = $requestId;
        $progress['status'] = 'running';
        $progress['started_at'] = $startedAt;
        $batches = 0;

        while (true) {
            if (! $shouldContinue()) {
                $this->requests->markRunning($requestId, $hostname, $progress + ['batches' => $batches]);

                return $this->finishManualCleanup(
                    $requestId,
                    $hostname,
                    'timed_out',
                    array_replace($progress, ['batches' => $batches, 'error_type' => 'stopped'])
                );
            }

            if ($this->now() >= $deadlineAt) {
                $this->requests->markRunning($requestId, $hostname, $progress + ['batches' => $batches]);

                return $this->finishManualCleanup(
                    $requestId,
                    $hostname,
                    'timed_out',
                    array_replace($progress, ['batches' => $batches, 'error_type' => 'deadline_exceeded'])
                );
            }

            try {
                $batch = $this->cleaner->clean(
                    $directory,
                    $cutoffTimestamp,
                    $settings['delete_limit'],
                    fn (): bool => $shouldContinue() && $this->now() < $deadlineAt
                );
            } catch (Throwable $exception) {
                $this->requests->markRunning($requestId, $hostname, $progress + ['batches' => $batches]);

                return $this->finishManualCleanup(
                    $requestId,
                    $hostname,
                    'failed',
                    array_replace($progress, ['batches' => $batches, 'error_type' => $exception::class])
                );
            }

            $batches++;
            $progress['scanned'] += $batch->scanned;
            $progress['deleted'] += $batch->deleted;
            $progress['failed'] += $batch->failed;
            $progress['error_type'] = $batch->errorType;
            $this->requests->markRunning($requestId, $hostname, $progress + ['batches' => $batches]);

            if ($batch->interrupted) {
                return $this->finishManualCleanup(
                    $requestId,
                    $hostname,
                    'timed_out',
                    array_replace($progress, ['batches' => $batches, 'error_type' => 'stopped'])
                );
            }

            if ($batch->errorType !== null || $batch->failed > 0) {
                return $this->finishManualCleanup(
                    $requestId,
                    $hostname,
                    'failed',
                    $progress + ['batches' => $batches]
                );
            }

            if (! $batch->limitReached) {
                return $this->finishManualCleanup(
                    $requestId,
                    $hostname,
                    'succeeded',
                    $progress + ['batches' => $batches]
                );
            }

            $sleep($settings['backlog_retry_seconds']);
        }
    }

    /**
     * @param array<string, int|bool> $settings
     * @param callable(): bool $shouldContinue
     * @param callable(int): void $sleep
     * @return array<string, int|string|null>
     */
    private function runAutomaticCleanup(
        string $directory,
        array $settings,
        callable $shouldContinue,
        callable $sleep
    ): array {
        $cleanup = $this->emptyCleanup();
        $cleanup['status'] = 'running';
        $cleanup['started_at'] = $this->now();
        $cutoffTimestamp = $cleanup['started_at'] - $settings['stale_seconds'];

        while ($shouldContinue()) {
            try {
                $batch = $this->cleaner->clean(
                    $directory,
                    $cutoffTimestamp,
                    $settings['delete_limit'],
                    $shouldContinue
                );
            } catch (Throwable $exception) {
                return $this->completeCleanup($cleanup, 'failed', $exception::class);
            }

            $cleanup['scanned'] += $batch->scanned;
            $cleanup['deleted'] += $batch->deleted;
            $cleanup['failed'] += $batch->failed;
            $cleanup['error_type'] = $batch->errorType;

            if ($batch->interrupted) {
                return $this->completeCleanup($cleanup, 'timed_out', 'stopped');
            }

            if ($batch->errorType !== null || $batch->failed > 0) {
                return $this->completeCleanup($cleanup, 'failed', $cleanup['error_type']);
            }

            if (! $batch->limitReached) {
                return $this->completeCleanup($cleanup, 'succeeded');
            }

            $sleep($settings['backlog_retry_seconds']);
        }

        return $this->completeCleanup($cleanup, 'timed_out', 'stopped');
    }

    /**
     * @param array<string, int|string|null> $result
     * @return array<string, int|string|null>
     */
    private function finishManualCleanup(
        string $requestId,
        string $hostname,
        string $status,
        array $result
    ): array {
        $result = $this->completeCleanup($result, $status, $result['error_type'] ?? null);
        $this->requests->markFinished($requestId, $hostname, $status, $result);

        return $result;
    }

    /**
     * @param array<string, int|string|null> $cleanup
     * @return array<string, int|string|null>
     */
    private function completeCleanup(array $cleanup, string $status, ?string $errorType = null): array
    {
        $cleanup['status'] = $status;
        $cleanup['finished_at'] = $this->now();
        $cleanup['error_type'] = $errorType;

        return $cleanup;
    }

    /** @return array<string, int|string|null> */
    private function emptyCleanup(): array
    {
        return [
            'request_id' => null,
            'status' => null,
            'started_at' => null,
            'finished_at' => null,
            'scanned' => 0,
            'deleted' => 0,
            'failed' => 0,
            'error_type' => null,
        ];
    }

    /** @return array<string, int|float|string|bool|null> */
    private function emptyFilesystem(): array
    {
        return [
            'ok' => false,
            'total_bytes' => null,
            'free_bytes' => null,
            'used_bytes' => null,
            'used_percent' => null,
            'error_type' => null,
        ];
    }

    /** @return array<string, int|bool|string|null> */
    private function emptyInventory(): array
    {
        return [
            'scanned' => null,
            'matching_files' => null,
            'matching_bytes' => null,
            'stale_files' => null,
            'stale_bytes' => null,
            'oldest_mtime' => null,
            'complete' => null,
            'duration_milliseconds' => null,
            'error_type' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    /**
     * @return array<string, int|bool|string|null>
     */
    private function inventoryPayload(
        SwooleTaskTmpInventoryResult $result,
        int $startedAt,
        int $finishedAt
    ): array {
        return [
            'scanned' => $result->scanned,
            'matching_files' => $result->matchingFiles,
            'matching_bytes' => $result->matchingBytes,
            'stale_files' => $result->staleFiles,
            'stale_bytes' => $result->staleBytes,
            'oldest_mtime' => $result->oldestMtime,
            'complete' => $result->complete,
            'duration_milliseconds' => $result->durationMilliseconds,
            'error_type' => $result->errorType,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ];
    }

    /** @return array<string, int|bool> */
    private function settings(): array
    {
        return [
            'auto_cleanup_enabled' => (bool) $this->config->get('swoole_task_tmp_cleaner.auto_cleanup_enabled', false),
            'stale_seconds' => max(1, (int) $this->config->get('swoole_task_tmp_cleaner.stale_seconds', 60)),
            'filesystem_stats_interval_seconds' => max(1, (int) $this->config->get('swoole_task_tmp_cleaner.filesystem_stats_interval_seconds', 5)),
            'inventory_interval_seconds' => max(1, (int) $this->config->get('swoole_task_tmp_cleaner.inventory_interval_seconds', 300)),
            'command_poll_interval_seconds' => max(1, (int) $this->config->get('swoole_task_tmp_cleaner.command_poll_interval_seconds', 1)),
            'backlog_retry_seconds' => max(1, (int) $this->config->get('swoole_task_tmp_cleaner.backlog_retry_seconds', 1)),
            'delete_limit' => max(1, (int) $this->config->get('swoole_task_tmp_cleaner.delete_limit', 100000)),
        ];
    }

    private function now(): int
    {
        return max(0, ($this->clock)());
    }
}
