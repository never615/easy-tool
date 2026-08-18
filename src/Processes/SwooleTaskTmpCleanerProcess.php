<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Processes;

use Hhxsv5\LaravelS\Swoole\Process\CustomProcessInterface;
use Illuminate\Support\Facades\Log;
use Mallto\Tool\Domain\AppStats\SwooleInstanceIdentity;
use Mallto\Tool\Domain\SwooleTaskTmp\SwooleTaskTmpRuntime;
use Swoole\Http\Server;
use Swoole\Process;
use Throwable;

/**
 * 适配 LaravelS 自定义进程到 task tmp Pod runtime。
 */
class SwooleTaskTmpCleanerProcess implements CustomProcessInterface
{
    private static bool $running = true;

    public static function callback(Server $swoole, Process $process)
    {
        self::$running = true;

        $directory = (string) config('laravels.swoole.task_tmpdir');
        $hostname = gethostname() ?: 'unknown';
        $stats = $swoole->stats();
        $instanceId = app(SwooleInstanceIdentity::class)->make(
            $hostname,
            (int) ($stats['start_time'] ?? 0)
        );
        $runtime = app(SwooleTaskTmpRuntime::class);

        Log::info('[SwooleTaskTmpCleaner] started', [
            'directory' => $directory,
            'instance_id' => $instanceId,
        ]);

        try {
            $runtime->run(
                $hostname,
                $instanceId,
                $directory,
                static fn (): bool => self::$running,
                static function (int $seconds): void {
                    self::sleepWhileRunning($seconds);
                }
            );
        } catch (Throwable $exception) {
            Log::warning('[SwooleTaskTmpCleaner] runtime failed', [
                'error_type' => $exception::class,
            ]);
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
