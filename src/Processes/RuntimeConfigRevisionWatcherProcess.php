<?php

namespace Mallto\Tool\Processes;

use Hhxsv5\LaravelS\Swoole\Process\CustomProcessInterface;
use Illuminate\Support\Facades\Log;
use Mallto\Tool\Domain\RuntimeConfig\RuntimeConfigRevisionStore;
use Mallto\Tool\Domain\RuntimeConfig\RuntimeConfigSwooleStore;
use Swoole\Http\Server;
use Swoole\Process;
use Throwable;

final class RuntimeConfigRevisionWatcherProcess implements CustomProcessInterface
{
    private static bool $running = true;

    public static function callback(Server $swoole, Process $process)
    {
        self::$running = true;
        $revisionStore = app(RuntimeConfigRevisionStore::class);
        $swooleStore = app(RuntimeConfigSwooleStore::class);
        $baseline = null;
        $pollSeconds = max(1, (int) config('runtime_config.revision_poll_seconds', 5));

        while (self::$running) {
            try {
                $baseline = self::reconcile($revisionStore, $swooleStore, $baseline);
            } catch (Throwable $exception) {
                Log::warning('[RuntimeConfigRevisionWatcher] reconciliation failed', [
                    'error_type' => $exception::class,
                ]);
            }

            for ($tick = 0; $tick < $pollSeconds * 10 && self::$running; $tick++) {
                \Swoole\Coroutine::sleep(0.1);
            }
        }
    }

    public static function reconcile(
        RuntimeConfigRevisionStore $revisionStore,
        RuntimeConfigSwooleStore $swooleStore,
        ?int $baseline
    ): ?int {
        $current = $revisionStore->current();
        if ($baseline !== null && $current === $baseline) {
            return $baseline;
        }

        return $swooleStore->flush() ? $current : $baseline;
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
