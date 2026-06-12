<?php

namespace Mallto\Tool\Processes;

use Hhxsv5\LaravelS\Swoole\Process\CustomProcessInterface;
use Illuminate\Support\Facades\Log;
use Mallto\Tool\Domain\NewConfig\LaravelSRestartService;
use Mallto\Tool\Domain\NewConfig\NewConfigVersionWatcher;
use Swoole\Http\Server;
use Swoole\Process;

class NewConfigVersionWatcherProcess implements CustomProcessInterface
{
    private static bool $running = true;

    public static function callback(Server $swoole, Process $process)
    {
        self::$running = true;
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

                Log::info('[NewConfigVersionWatcher] config generation changed, restarting current LaravelS instance', $state);
                $result = app(LaravelSRestartService::class)->restartCurrentLaravelSInstance();
                Log::info('[NewConfigVersionWatcher] restart scheduled', $result);
                self::$running = false;
            } catch (\Throwable $exception) {
                Log::warning('[NewConfigVersionWatcher] watch failed: ' . $exception->getMessage());
            }
        }

        Log::info('[NewConfigVersionWatcher] stopped');
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
