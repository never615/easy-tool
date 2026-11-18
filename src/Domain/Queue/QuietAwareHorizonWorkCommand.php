<?php

namespace Mallto\Tool\Domain\Queue;

use Illuminate\Contracts\Queue\Job;
use Laravel\Horizon\Console\WorkCommand;
use Throwable;

/** 避免格式化静默控制台会丢弃的逐任务状态文本。 */
class QuietAwareHorizonWorkCommand extends WorkCommand
{
    public static function fromOriginal(WorkCommand $command): self
    {
        // 保留 Horizon 显式注入的工作进程和缓存，包括装饰后的依赖。
        return new self($command->worker, $command->cache);
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
