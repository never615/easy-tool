<?php

namespace Mallto\Tool\Domain\SwooleTaskMonitor;

use Hhxsv5\LaravelS\Swoole\Task\Task;
use Throwable;

abstract class MonitoredSwooleTask extends Task
{
    private ?string $swooleTaskMonitorId = null;
    private array $swooleTaskMonitorContext = [];
    private bool $swooleTaskMonitorTraceSampled = false;

    final public function handle()
    {
        $start = microtime(true);
        SwooleTaskMonitor::recordStarted($this);

        try {
            $this->handleTask();
        } catch (Throwable $exception) {
            SwooleTaskMonitor::recordFailed($this, $this->elapsedMs($start), $exception);

            throw $exception;
        }

        SwooleTaskMonitor::recordFinished($this, $this->elapsedMs($start));
    }

    abstract protected function handleTask(): void;

    public function setSwooleTaskMonitorContext(string $taskId, array $context = [], bool $traceSampled = false): void
    {
        $this->swooleTaskMonitorId = $taskId;
        $this->swooleTaskMonitorContext = $context;
        $this->swooleTaskMonitorTraceSampled = $traceSampled;
    }

    public function swooleTaskMonitorId(): string
    {
        if (!$this->swooleTaskMonitorId) {
            $this->swooleTaskMonitorId = SwooleTaskMonitor::newTaskId();
        }

        return $this->swooleTaskMonitorId;
    }

    public function swooleTaskMonitorContext(): array
    {
        return $this->swooleTaskMonitorContext;
    }

    public function swooleTaskMonitorTraceSampled(): bool
    {
        return $this->swooleTaskMonitorTraceSampled;
    }

    public function swooleTaskMonitorPayloadBytes(): int
    {
        return max(0, $this->monitorPayloadBytes());
    }

    protected function monitorPayloadBytes(): int
    {
        return 0;
    }

    protected function monitorValueBytes(mixed $value): int
    {
        if (is_string($value)) {
            return strlen($value);
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return 0;
        }

        return strlen($encoded);
    }

    private function elapsedMs(float $start): int
    {
        return (int)round((microtime(true) - $start) * 1000);
    }
}
