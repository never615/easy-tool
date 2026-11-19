<?php

namespace Mallto\Tool\Domain\Queue;

use Illuminate\Queue\Console\RetryCommand;

/** 保留框架重试语义，过载不得删除失败任务证据。 */
class BoundedQueueRetryCommand extends RetryCommand
{
    public function __construct() { parent::__construct(); }

    protected function retryJob($job)
    {
        if (!QueueBacklogProtection::enabled()) return parent::retryJob($job);

        $previous = $this->laravel->bound('queue.high_retry_context')
            ? $this->laravel->make('queue.high_retry_context') : false;
        $this->laravel->instance('queue.high_retry_context', true);
        try {
            return parent::retryJob($job);
        } finally {
            $this->laravel->instance('queue.high_retry_context', $previous);
        }
    }

    public function handle()
    {
        try {
            return parent::handle();
        } catch (QueueAdmissionRejected $error) {
            $this->components->warn($error->getMessage());
            return self::FAILURE;
        }
    }
}
