<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Processes;

use Hhxsv5\LaravelS\Swoole\Process\CustomProcessInterface;
use Illuminate\Support\Facades\Log;
use Mallto\Tool\Domain\SwooleTaskTmp\SwooleTaskTmpFileCleaner;
use Swoole\Http\Server;
use Swoole\Process;
use Throwable;

/**
 * 清理当前 LaravelS 实例 task_tmpdir 中失去实时价值的 Swoole Task 临时文件。
 */
class SwooleTaskTmpCleanerProcess implements CustomProcessInterface
{
    private static bool $running = true;

    public static function callback(Server $swoole, Process $process)
    {
        self::$running = true;

        $directory = (string) config('laravels.swoole.task_tmpdir');
        $staleSeconds = max(1, (int) config('swoole_task_tmp_cleaner.stale_seconds', 60));
        $scanIntervalSeconds = max(1, (int) config('swoole_task_tmp_cleaner.scan_interval_seconds', 30));
        $backlogRetrySeconds = max(1, (int) config('swoole_task_tmp_cleaner.backlog_retry_seconds', 1));
        $deleteLimit = max(1, (int) config('swoole_task_tmp_cleaner.delete_limit', 100000));
        $cleaner = new SwooleTaskTmpFileCleaner();

        Log::info('[SwooleTaskTmpCleaner] started', [
            'directory' => $directory,
            'stale_seconds' => $staleSeconds,
            'scan_interval_seconds' => $scanIntervalSeconds,
            'backlog_retry_seconds' => $backlogRetrySeconds,
            'delete_limit' => $deleteLimit,
        ]);

        while (self::$running) {
            $nextDelaySeconds = $scanIntervalSeconds;

            try {
                $result = $cleaner->clean(
                    $directory,
                    time() - $staleSeconds,
                    $deleteLimit,
                    static fn (): bool => self::$running
                );

                $context = [
                    'scanned' => $result->scanned,
                    'deleted' => $result->deleted,
                    'failed' => $result->failed,
                    'limit_reached' => $result->limitReached,
                    'interrupted' => $result->interrupted,
                    'duration_ms' => $result->durationMilliseconds,
                ];

                if ($result->deleted > 0 || $result->limitReached) {
                    Log::info('[SwooleTaskTmpCleaner] cleanup completed', $context);
                }

                if ($result->failed > 0 || $result->errorType !== null) {
                    Log::warning('[SwooleTaskTmpCleaner] cleanup encountered failures', $context + [
                        'error_type' => $result->errorType,
                    ]);
                }

                if ($result->limitReached) {
                    $nextDelaySeconds = $backlogRetrySeconds;
                }
            } catch (Throwable $exception) {
                Log::warning('[SwooleTaskTmpCleaner] cleanup failed', [
                    'error_type' => $exception::class,
                ]);
            }

            self::sleepWhileRunning($nextDelaySeconds);
        }

        Log::info('[SwooleTaskTmpCleaner] stopped');
    }

    private static function sleepWhileRunning(int $seconds): void
    {
        for ($tick = 0; $tick < $seconds * 10 && self::$running; $tick++) {
            \Swoole\Coroutine::sleep(0.1);
        }
    }

    public static function onReload(Server $swoole, Process $process)
    {
        self::$running = false;
    }

    public static function onStop(Server $swoole, Process $process)
    {
        self::$running = false;
    }
}
