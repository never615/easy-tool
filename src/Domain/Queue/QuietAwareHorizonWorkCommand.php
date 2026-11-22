<?php

namespace Mallto\Tool\Domain\Queue;

use Illuminate\Contracts\Queue\Job;
use Laravel\Horizon\Console\WorkCommand;
use Throwable;

/** 支持独立队列的 Worker 设置，并跳过静默控制台的状态格式化。 */
class QuietAwareHorizonWorkCommand extends WorkCommand
{
    public static function fromOriginal(WorkCommand $command): self
    {
        // 保留 Horizon 显式注入的工作进程和缓存，包括装饰后的依赖。
        return new self($command->worker, $command->cache);
    }

    protected function runWorker($connection, $queue)
    {
        $overrides = $this->laravel['config']->get('horizon.worker_queue_overrides', []);
        $override = $overrides[$connection][$queue] ?? null;
        if ($override !== null) {
            // Each auto-balanced worker consumes one queue. Leave workers with
            // a comma-separated queue list on their original connection.
            $connection = $override['connection'];
            $this->input->setOption('timeout', $override['timeout']);
        }

        return parent::runWorker($connection, $queue);
    }

    protected function writeOutput(Job $job, $status, ?Throwable $exception = null)
    {
        if ($this->output->isQuiet()) {
            // 保留展示定时器，以支持输出适配器后续改变详细级别。
            if ($status === 'starting') $this->latestStartedAt = microtime(true);
            return;
        }

        parent::writeOutput($job, $status, $exception);
    }
}
