<?php

namespace Mallto\Tool\Domain\SwooleTaskMonitor;

use Hhxsv5\LaravelS\Swoole\Task\Task;
use Illuminate\Support\Facades\Log;
use Mallto\Tool\Data\NewConfig;
use Mallto\Tool\Domain\NewConfig\NewConfigCenter;
use Throwable;

class SwooleTaskMonitor
{
    private const MODE_OFF = 'off';
    private const MODE_SUMMARY = 'summary';
    private const MODE_TRACE = 'trace';

    private static int $lastRecordFailureLogAt = 0;

    public static function deliver(Task $task, array $context = []): bool
    {
        $taskClass = get_class($task);
        $traceSampled = self::traceSampled();
        $taskId = $traceSampled ? self::taskId($task) : '';
        $payloadBytes = $traceSampled ? self::payloadBytes($task) : 0;

        if (method_exists($task, 'setSwooleTaskMonitorContext')) {
            $task->setSwooleTaskMonitorContext($taskId, $context, $traceSampled);
        }

        self::safeRecord(function (SwooleTaskMonitorStore $store) use ($traceSampled, $taskId, $taskClass, $payloadBytes, $context) {
            if ($traceSampled) {
                $store->recordSubmitted($taskId, $taskClass, $payloadBytes, $context);

                return;
            }

            $store->recordSubmittedSummary($taskClass);
        });

        try {
            $delivered = Task::deliver($task);
        } catch (Throwable $exception) {
            self::safeRecord(function (SwooleTaskMonitorStore $store) use ($taskId, $taskClass, $traceSampled, $exception) {
                $store->recordDeliverFailed(
                    $taskId,
                    $taskClass,
                    'exception: ' . $exception->getMessage(),
                    $exception,
                    $traceSampled
                );
            });

            throw $exception;
        }

        if ($delivered === false) {
            self::safeRecord(function (SwooleTaskMonitorStore $store) use ($taskId, $taskClass, $traceSampled) {
                $store->recordDeliverFailed($taskId, $taskClass, 'Task::deliver returned false', null, $traceSampled);
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

    public static function recordRateLimited(string|Task $task, string $reason, array $context = []): void
    {
        $taskClass = is_string($task) ? $task : get_class($task);

        self::safeRecord(function (SwooleTaskMonitorStore $store) use ($taskClass, $reason, $context) {
            $store->recordRateLimited($taskClass, $reason, $context);
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
            if (!$task->swooleTaskMonitorTraceSampled()) {
                $store->recordStartedSummary(get_class($task));

                return;
            }

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
            if (!$task->swooleTaskMonitorTraceSampled()) {
                $store->recordFinishedSummary(get_class($task), $durationMs);

                return;
            }

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
            if (!$task->swooleTaskMonitorTraceSampled()) {
                $store->recordFailedSummary(get_class($task), $durationMs, $exception);

                return;
            }

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

    public static function config(): array
    {
        $enabled = self::enabled();
        $mode = self::mode();
        $traceSampleRate = self::traceSampleRate($mode);

        return [
            'enabled' => $enabled,
            'mode' => $enabled ? $mode : self::MODE_OFF,
            'trace_sample_rate' => $enabled ? $traceSampleRate : 0.0,
            'trace_enabled' => $enabled && $traceSampleRate > 0,
        ];
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
        if (self::mode() === self::MODE_OFF) {
            return false;
        }

        $enabled = self::newConfig()->get(NewConfig::KEY_SWOOLE_TASK_MONITOR_ENABLED, null);
        if ($enabled === null) {
            $enabled = config('swoole_task_monitor.enabled', env('SWOOLE_TASK_MONITOR_ENABLED', false));
        }

        if (is_string($enabled)) {
            return !in_array(strtolower($enabled), ['0', 'false', 'off', 'no'], true);
        }

        return (bool)$enabled;
    }

    private static function traceSampled(): bool
    {
        if (!self::enabled()) {
            return false;
        }

        $rate = self::traceSampleRate(self::mode());
        if ($rate <= 0) {
            return false;
        }

        if ($rate >= 1) {
            return true;
        }

        return random_int(1, 1000000) <= (int)round($rate * 1000000);
    }

    private static function mode(): string
    {
        $mode = self::newConfig()->string(NewConfig::KEY_SWOOLE_TASK_MONITOR_MODE, '');
        if ($mode === '') {
            $mode = (string)config(
                'swoole_task_monitor.mode',
                env('SWOOLE_TASK_MONITOR_MODE', self::MODE_SUMMARY)
            );
        }

        $mode = strtolower(trim($mode));

        return in_array($mode, [self::MODE_OFF, self::MODE_SUMMARY, self::MODE_TRACE], true)
            ? $mode
            : self::MODE_SUMMARY;
    }

    private static function traceSampleRate(string $mode): float
    {
        $default = $mode === self::MODE_TRACE ? 1.0 : 0.0;
        $rate = self::newConfig()->get(NewConfig::KEY_SWOOLE_TASK_MONITOR_TRACE_SAMPLE_RATE, null);
        if ($rate === null || $rate === '') {
            $rate = config('swoole_task_monitor.trace_sample_rate');
        }
        if ($rate === null || $rate === '') {
            $rate = env('SWOOLE_TASK_MONITOR_TRACE_SAMPLE_RATE', $default);
        }

        return max(0.0, min(1.0, (float)$rate));
    }

    private static function newConfig(): NewConfigCenter
    {
        return app(NewConfigCenter::class);
    }
}
