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
        private readonly SwooleTaskTmpCleanupRequestCoordinator $requests,
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
        $nextAutomaticCleanupAt = 0;
        $filesystem = $this->emptyFilesystem();
        $filesystemSampledAt = null;
        $inventory = $this->emptyInventory();
        $inventoryStartedAt = null;
        $cleanup = $this->emptyCleanup();
        $manualCleanup = null;
        $automaticCleanup = null;
        $automaticCleanupBlockedUntil = null;

        try {
            while (true) {
                if (! $shouldContinue()) {
                    if ($manualCleanup !== null) {
                        $cleanup = $this->finishManualCleanup(
                            $manualCleanup,
                            $hostname,
                            'timed_out',
                            'stopped'
                        );
                    }

                    break;
                }

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

                $manualLeaseLost = false;
                if ($manualCleanup === null) {
                    $command = $this->requests->claim($hostname, $instanceId, $now);
                    if ($command !== null) {
                        $manualCleanup = $this->newManualCleanup($command, $settings, $now);
                        $automaticCleanupBlockedUntil = null;
                        $cleanup = $this->manualPayload($manualCleanup);
                    }
                } elseif (! $this->requests->renew($manualCleanup['request_id'], $hostname, $instanceId, $now)) {
                    // Do not continue deleting after this runtime loses the Redis lease. Another
                    // runtime may have claimed the same target after the original lease expired.
                    $cleanup = $this->completeCleanup($manualCleanup['cleanup'], 'timed_out', 'lease_lost');
                    $automaticCleanupBlockedUntil = $manualCleanup['deadline_at'];
                    $manualCleanup = null;
                    $manualLeaseLost = true;
                }

                if ($manualCleanup !== null && $now >= $manualCleanup['next_attempt_at']) {
                    $manualDeadlineAt = $manualCleanup['deadline_at'];
                    [$manualCleanup, $cleanup] = $this->runManualBatch(
                        $manualCleanup,
                        $hostname,
                        $instanceId,
                        $directory,
                        $settings,
                        $shouldContinue
                    );
                    if ($manualCleanup === null && $cleanup['error_type'] === 'lease_lost') {
                        $automaticCleanupBlockedUntil = $manualDeadlineAt;
                        $manualLeaseLost = true;
                    }
                } elseif (
                    ! $manualLeaseLost
                    && $manualCleanup === null
                    && ($automaticCleanupBlockedUntil === null || $now >= $automaticCleanupBlockedUntil)
                    && $settings['auto_cleanup_enabled']
                ) {
                    if ($automaticCleanup === null && $now >= $nextAutomaticCleanupAt) {
                        $automaticCleanup = $this->newAutomaticCleanup($settings, $now);
                    }

                    if ($automaticCleanup !== null && $now >= $automaticCleanup['next_attempt_at']) {
                        [$automaticCleanup, $cleanup, $nextAutomaticCleanupAt] = $this->runAutomaticBatch(
                            $automaticCleanup,
                            $directory,
                            $settings,
                            $shouldContinue,
                            $now
                        );
                    }
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
     * @return array{request_id: string, deadline_at: int, cutoff_timestamp: int, next_attempt_at: int, cleanup: array<string, int|string|null>}
     */
    private function newManualCleanup(array $command, array $settings, int $now): array
    {
        $cleanup = $this->emptyCleanup();
        $cleanup['request_id'] = $command['request_id'];
        $cleanup['status'] = 'running';
        $cleanup['started_at'] = $now;

        return [
            'request_id' => $command['request_id'],
            'deadline_at' => max(0, (int) $command['deadline_at']),
            'cutoff_timestamp' => $now - $settings['stale_seconds'],
            'next_attempt_at' => $now,
            'cleanup' => $cleanup,
        ];
    }

    /**
     * @param array{request_id: string, deadline_at: int, cutoff_timestamp: int, next_attempt_at: int, cleanup: array<string, int|string|null>} $state
     * @param array<string, int|bool> $settings
     * @param callable(): bool $shouldContinue
     * @return array{0: array{request_id: string, deadline_at: int, cutoff_timestamp: int, next_attempt_at: int, cleanup: array<string, int|string|null>}|null, 1: array<string, int|string|null>}
     */
    private function runManualBatch(
        array $state,
        string $hostname,
        string $instanceId,
        string $directory,
        array $settings,
        callable $shouldContinue
    ): array {
        $cleanup = $state['cleanup'];
        $leaseLost = false;
        $nextLeaseRenewAt = $this->now() + $this->leaseHeartbeatSeconds($settings);

        if (! $shouldContinue()) {
            return [null, $this->finishManualCleanup($state, $hostname, 'timed_out', 'stopped')];
        }

        if ($this->now() >= $state['deadline_at']) {
            return [null, $this->finishManualCleanup($state, $hostname, 'timed_out', 'deadline_exceeded')];
        }

        try {
            $batch = $this->cleaner->clean(
                $directory,
                $state['cutoff_timestamp'],
                $settings['delete_limit'],
                function () use (
                    &$leaseLost,
                    &$nextLeaseRenewAt,
                    $state,
                    $hostname,
                    $instanceId,
                    $settings,
                    $shouldContinue
                ): bool {
                    if (! $shouldContinue()) {
                        return false;
                    }

                    $now = $this->now();
                    if ($now >= $state['deadline_at']) {
                        return false;
                    }

                    if ($now < $nextLeaseRenewAt) {
                        return true;
                    }

                    if (! $this->requests->renew($state['request_id'], $hostname, $instanceId, $now)) {
                        $leaseLost = true;

                        return false;
                    }

                    $nextLeaseRenewAt = $now + $this->leaseHeartbeatSeconds($settings);

                    return true;
                }
            );
        } catch (Throwable $exception) {
            return [null, $this->finishManualCleanup($state, $hostname, 'failed', $exception::class)];
        }

        $cleanup = $this->addBatch($cleanup, $batch);
        $state['cleanup'] = $cleanup;

        if (! $leaseLost && ! $this->requests->renew(
            $state['request_id'],
            $hostname,
            $instanceId,
            $this->now()
        )) {
            $leaseLost = true;
        }

        if ($leaseLost) {
            // The result might now belong to another runtime. Keep this local snapshot only;
            // writing markRunning/markFinished could overwrite the lease holder's progress.
            return [null, $this->completeCleanup($cleanup, 'timed_out', 'lease_lost')];
        }

        $this->requests->markRunning($state['request_id'], $hostname, $cleanup);

        if ($batch->interrupted) {
            $errorType = ! $shouldContinue()
                ? 'stopped'
                : ($this->now() >= $state['deadline_at'] ? 'deadline_exceeded' : 'stopped');

            return [null, $this->finishManualCleanup($state, $hostname, 'timed_out', $errorType)];
        }

        if ($batch->errorType !== null || $batch->failed > 0) {
            return [null, $this->finishManualCleanup($state, $hostname, 'failed', $batch->errorType)];
        }

        if (! $batch->limitReached) {
            return [null, $this->finishManualCleanup($state, $hostname, 'succeeded', null)];
        }

        $state['next_attempt_at'] = $this->now() + $settings['backlog_retry_seconds'];

        return [$state, $this->manualPayload($state)];
    }

    /**
     * @param array<string, int|bool> $settings
     * @return array{cutoff_timestamp: int, next_attempt_at: int, cleanup: array<string, int|string|null>}
     */
    private function newAutomaticCleanup(array $settings, int $now): array
    {
        $cleanup = $this->emptyCleanup();
        $cleanup['status'] = 'running';
        $cleanup['started_at'] = $now;

        return [
            'cutoff_timestamp' => $now - $settings['stale_seconds'],
            'next_attempt_at' => $now,
            'cleanup' => $cleanup,
        ];
    }

    /**
     * @param array{cutoff_timestamp: int, next_attempt_at: int, cleanup: array<string, int|string|null>} $state
     * @param array<string, int|bool> $settings
     * @param callable(): bool $shouldContinue
     * @return array{0: array{cutoff_timestamp: int, next_attempt_at: int, cleanup: array<string, int|string|null>}|null, 1: array<string, int|string|null>, 2: int}
     */
    private function runAutomaticBatch(
        array $state,
        string $directory,
        array $settings,
        callable $shouldContinue,
        int $now
    ): array {
        $cleanup = $state['cleanup'];

        try {
            $batch = $this->cleaner->clean(
                $directory,
                $state['cutoff_timestamp'],
                $settings['delete_limit'],
                $shouldContinue
            );
        } catch (Throwable $exception) {
            return [null, $this->completeCleanup($cleanup, 'failed', $exception::class), $now + $settings['scan_interval_seconds']];
        }

        $cleanup = $this->addBatch($cleanup, $batch);
        if ($batch->interrupted) {
            return [null, $this->completeCleanup($cleanup, 'timed_out', 'stopped'), $now + $settings['scan_interval_seconds']];
        }

        if ($batch->errorType !== null || $batch->failed > 0) {
            return [null, $this->completeCleanup($cleanup, 'failed', $batch->errorType), $now + $settings['scan_interval_seconds']];
        }

        if (! $batch->limitReached) {
            return [null, $this->completeCleanup($cleanup, 'succeeded'), $now + $settings['scan_interval_seconds']];
        }

        $state['cleanup'] = $cleanup;
        $state['next_attempt_at'] = $now + $settings['backlog_retry_seconds'];

        return [$state, $cleanup, $now];
    }

    /**
     * @param array{request_id: string, deadline_at: int, cutoff_timestamp: int, next_attempt_at: int, cleanup: array<string, int|string|null>} $state
     * @return array<string, int|string|null>
     */
    private function finishManualCleanup(array $state, string $hostname, string $status, ?string $errorType): array
    {
        $cleanup = $this->completeCleanup($state['cleanup'], $status, $errorType);
        $this->requests->markFinished($state['request_id'], $hostname, $status, $cleanup);

        return $cleanup;
    }

    /**
     * @param array{request_id: string, deadline_at: int, cutoff_timestamp: int, next_attempt_at: int, cleanup: array<string, int|string|null>} $state
     * @return array<string, int|string|null>
     */
    private function manualPayload(array $state): array
    {
        return $state['cleanup'];
    }

    /**
     * @param array<string, int|string|null> $cleanup
     * @return array<string, int|string|null>
     */
    private function addBatch(array $cleanup, SwooleTaskTmpCleanupResult $batch): array
    {
        $cleanup['scanned'] += $batch->scanned;
        $cleanup['deleted'] += $batch->deleted;
        $cleanup['failed'] += $batch->failed;
        $cleanup['batches'] += 1;
        $cleanup['error_type'] = $batch->errorType;

        return $cleanup;
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
            'batches' => 0,
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
            'scan_interval_seconds' => max(1, (int) $this->config->get('swoole_task_tmp_cleaner.scan_interval_seconds', 30)),
            'backlog_retry_seconds' => max(1, (int) $this->config->get('swoole_task_tmp_cleaner.backlog_retry_seconds', 1)),
            'delete_limit' => max(1, (int) $this->config->get('swoole_task_tmp_cleaner.delete_limit', 100000)),
            'cleanup_lease_seconds' => max(1, (int) $this->config->get('swoole_task_tmp_cleaner.cleanup_lease_seconds', 30)),
        ];
    }

    /** @param array<string, int|bool> $settings */
    private function leaseHeartbeatSeconds(array $settings): int
    {
        return max(1, intdiv($settings['cleanup_lease_seconds'], 2));
    }

    private function now(): int
    {
        return max(0, ($this->clock)());
    }
}
