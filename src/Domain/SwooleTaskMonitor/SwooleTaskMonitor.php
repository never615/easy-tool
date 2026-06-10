<?php

namespace Mallto\Tool\Domain\SwooleTaskMonitor;

use Hhxsv5\LaravelS\Swoole\Task\Task;
use Illuminate\Support\Facades\Log;
use Throwable;

class SwooleTaskMonitor
{
    private static int $lastRecordFailureLogAt = 0;

    public static function deliver(Task $task, array $context = []): bool
    {
        $taskId = self::taskId($task);
        $taskClass = get_class($task);
        $payloadBytes = self::payloadBytes($task);

        if (method_exists($task, 'setSwooleTaskMonitorContext')) {
            $task->setSwooleTaskMonitorContext($taskId, $context);
        }

        self::safeRecord(function (SwooleTaskMonitorStore $store) use ($taskId, $taskClass, $payloadBytes, $context) {
            $store->recordSubmitted($taskId, $taskClass, $payloadBytes, $context);
        });

        try {
            $delivered = Task::deliver($task);
        } catch (Throwable $exception) {
            self::safeRecord(function (SwooleTaskMonitorStore $store) use ($taskId, $taskClass, $exception) {
                $store->recordDeliverFailed($taskId, $taskClass, 'exception: ' . $exception->getMessage(), $exception);
            });

            throw $exception;
        }

        if ($delivered === false) {
            self::safeRecord(function (SwooleTaskMonitorStore $store) use ($taskId, $taskClass) {
                $store->recordDeliverFailed($taskId, $taskClass, 'Task::deliver returned false');
            });

            return false;
        }

        self::safeRecord(function (SwooleTaskMonitorStore $store) use ($taskClass) {
            $store->recordDelivered($taskClass);
        });

        return true;
    }

    public static function recordDropped(string|Task $task, string $reason, array $context = []): void
    {
        $taskClass = is_string($task) ? $task : get_class($task);

        self::safeRecord(function (SwooleTaskMonitorStore $store) use ($taskClass, $reason, $context) {
            $store->recordDropped($taskClass, $reason, $context);
        });
    }

    public static function recordDirectHandled(string|Task $task, string $reason, array $context = []): void
    {
        $taskClass = is_string($task) ? $task : get_class($task);

        self::safeRecord(function (SwooleTaskMonitorStore $store) use ($taskClass, $reason, $context) {
            $store->recordDirectHandled($taskClass, $reason, $context);
        });
    }

    public static function recordStarted(MonitoredSwooleTask $task): void
    {
        self::safeRecord(function (SwooleTaskMonitorStore $store) use ($task) {
            $store->recordStarted(
                $task->swooleTaskMonitorId(),
                get_class($task),
                $task->swooleTaskMonitorPayloadBytes(),
                $task->swooleTaskMonitorContext()
            );
        });
    }

    public static function recordFinished(MonitoredSwooleTask $task, int $durationMs): void
    {
        self::safeRecord(function (SwooleTaskMonitorStore $store) use ($task, $durationMs) {
            $store->recordFinished(
                $task->swooleTaskMonitorId(),
                get_class($task),
                $durationMs,
                $task->swooleTaskMonitorPayloadBytes(),
                $task->swooleTaskMonitorContext()
            );
        });
    }

    public static function recordFailed(MonitoredSwooleTask $task, int $durationMs, Throwable $exception): void
    {
        self::safeRecord(function (SwooleTaskMonitorStore $store) use ($task, $durationMs, $exception) {
            $store->recordFailed(
                $task->swooleTaskMonitorId(),
                get_class($task),
                $durationMs,
                $task->swooleTaskMonitorPayloadBytes(),
                $exception,
                $task->swooleTaskMonitorContext()
            );
        });
    }

    private static function taskId(Task $task): string
    {
        if (method_exists($task, 'swooleTaskMonitorId')) {
            return $task->swooleTaskMonitorId();
        }

        return self::newTaskId();
    }

    public static function newTaskId(): string
    {
        return str_replace('.', '', uniqid('', true)) . mt_rand(1000, 9999);
    }

    private static function payloadBytes(Task $task): int
    {
        if (method_exists($task, 'swooleTaskMonitorPayloadBytes')) {
            return max(0, (int)$task->swooleTaskMonitorPayloadBytes());
        }

        return 0;
    }

    private static function safeRecord(callable $callback): void
    {
        if (!self::enabled()) {
            return;
        }

        try {
            $callback(app(SwooleTaskMonitorStore::class));
        } catch (Throwable $exception) {
            $now = time();
            if ($now - self::$lastRecordFailureLogAt >= 60) {
                self::$lastRecordFailureLogAt = $now;
                Log::warning('[SwooleTaskMonitor] record failed: ' . $exception->getMessage());
            }
        }
    }

    private static function enabled(): bool
    {
        return (bool)config('swoole_task_monitor.enabled', env('SWOOLE_TASK_MONITOR_ENABLED', true));
    }
}
