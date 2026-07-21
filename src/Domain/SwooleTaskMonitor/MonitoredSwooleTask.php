<?php

namespace Mallto\Tool\Domain\SwooleTaskMonitor;

use Hhxsv5\LaravelS\Swoole\Task\Task;
use Throwable;

/**
 * MonitoredSwooleTask：Swoole Task 生命周期监控组件。
 * 它记录任务状态，同时避免监控异常干扰业务任务。
 */
abstract class MonitoredSwooleTask extends Task
{
    private ?string $swooleTaskMonitorId = null;
    private array $swooleTaskMonitorContext = [];
    private bool $swooleTaskMonitorTraceSampled = false;

    /**
     * 处理「handle」相关的内部协作步骤。
     */
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

    /**
     * 处理「handleTask」相关的内部协作步骤。
     */
    abstract protected function handleTask(): void;

    /**
     * 处理「setSwooleTaskMonitorContext」相关的内部协作步骤。
     */
    public function setSwooleTaskMonitorContext(string $taskId, array $context = [], bool $traceSampled = false): void
    {
        $this->swooleTaskMonitorId = $taskId;
        $this->swooleTaskMonitorContext = $context;
        $this->swooleTaskMonitorTraceSampled = $traceSampled;
    }

    /**
     * 处理「swooleTaskMonitorId」相关的内部协作步骤。
     */
    public function swooleTaskMonitorId(): string
    {
        if (!$this->swooleTaskMonitorId) {
            $this->swooleTaskMonitorId = SwooleTaskMonitor::newTaskId();
        }

        return $this->swooleTaskMonitorId;
    }

    /**
     * 处理「swooleTaskMonitorContext」相关的内部协作步骤。
     */
    public function swooleTaskMonitorContext(): array
    {
        return $this->swooleTaskMonitorContext;
    }

    /**
     * 处理「swooleTaskMonitorTraceSampled」相关的内部协作步骤。
     */
    public function swooleTaskMonitorTraceSampled(): bool
    {
        return $this->swooleTaskMonitorTraceSampled;
    }

    /**
     * 处理「swooleTaskMonitorPayloadBytes」相关的内部协作步骤。
     */
    public function swooleTaskMonitorPayloadBytes(): int
    {
        return max(0, $this->monitorPayloadBytes());
    }

    /**
     * 处理「monitorPayloadBytes」相关的内部协作步骤。
     */
    protected function monitorPayloadBytes(): int
    {
        return 0;
    }

    /**
     * 处理「monitorValueBytes」相关的内部协作步骤。
     */
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

    /**
     * 处理「elapsedMs」相关的内部协作步骤。
     */
    private function elapsedMs(float $start): int
    {
        return (int)round((microtime(true) - $start) * 1000);
    }
}
