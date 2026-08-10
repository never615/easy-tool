<?php

namespace Mallto\Tool\Processes;

use Hhxsv5\LaravelS\Swoole\Process\CustomProcessInterface;
use Illuminate\Support\Facades\Log;
use Mallto\Tool\Domain\NewConfig\LaravelSRestartService;
use Mallto\Tool\Domain\NewConfig\NewConfigVersionWatcher;
use Swoole\Http\Server;
use Swoole\Process;

/**
 * NewConfigVersionWatcherProcess：运行在 LaravelS/Swoole 长驻进程中的 new config version watcher process。
 * 进程需要显式处理重载与停止事件，避免陈旧运行态影响其他 worker。
 */
class NewConfigVersionWatcherProcess implements CustomProcessInterface
{
    private static bool $running = true;

    /**
     * 处理「callback」相关的内部协作步骤。
     */
    public static function callback(Server $swoole, Process $process)
    {
        self::$running = true;
        if (!(bool)config('new_config.restart.enabled', true)) {
            Log::info('[NewConfigVersionWatcher] disabled by new_config.restart.enabled');

            return;
        }

        $pollSeconds = max(1, (int)config('new_config.watcher.poll_seconds', 10));

        try {
            $watcher = app(NewConfigVersionWatcher::class);
            $lastSeenGeneration = $watcher->currentGeneration();
            Log::info('[NewConfigVersionWatcher] started', [
                'generation' => $lastSeenGeneration,
                'poll_seconds' => $pollSeconds,
            ]);
        } catch (\Throwable $exception) {
            $lastSeenGeneration = null;
            Log::warning('[NewConfigVersionWatcher] initial generation read failed: ' . $exception->getMessage());
        }

        while (self::$running) {
            for ($i = 0; $i < $pollSeconds * 10 && self::$running; $i++) {
                \Swoole\Coroutine::sleep(0.1);
            }

            if (!self::$running) {
                break;
            }

            try {
                if (!(bool)config('new_config.restart.enabled', true)) {
                    Log::info('[NewConfigVersionWatcher] stopped because restart is disabled');
                    break;
                }

                $watcher = app(NewConfigVersionWatcher::class);
                if ($lastSeenGeneration === null) {
                    $lastSeenGeneration = $watcher->currentGeneration();
                    Log::info('[NewConfigVersionWatcher] baseline generation initialized', [
                        'generation' => $lastSeenGeneration,
                    ]);
                    continue;
                }

                $state = $watcher->changedSince($lastSeenGeneration);
                if (!($state['changed'] ?? false)) {
                    $lastSeenGeneration = (int)($state['current_generation'] ?? $lastSeenGeneration);
                    continue;
                }

                if (self::restartIfChanged($state)) {
                    self::$running = false;
                }
            } catch (\Throwable $exception) {
                Log::warning('[NewConfigVersionWatcher] watch failed: ' . $exception->getMessage());
            }
        }

        Log::info('[NewConfigVersionWatcher] stopped');
    }

    public static function restartIfChanged(array $state, ?LaravelSRestartService $restartService = null): bool
    {
        if (!($state['changed'] ?? false) || !(bool)config('new_config.restart.enabled', true)) {
            return false;
        }

        Log::info('[NewConfigVersionWatcher] config generation changed, restarting current LaravelS instance', $state);
        $result = ($restartService ?? app(LaravelSRestartService::class))->restartCurrentLaravelSInstance();
        Log::info('[NewConfigVersionWatcher] restart scheduled', $result);

        return !($result['skipped'] ?? false);
    }

    /**
     * 处理「onReload」相关的内部协作步骤。
     */
    public static function onReload(Server $swoole, Process $process)
    {
        self::$running = false;
    }

    /**
     * 处理「onStop」相关的内部协作步骤。
     */
    public static function onStop(Server $swoole, Process $process)
    {
        self::$running = false;
    }
}
