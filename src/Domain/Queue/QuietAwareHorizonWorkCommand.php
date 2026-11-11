<?php

namespace Mallto\Tool\Domain\Queue;

use Illuminate\Contracts\Queue\Job;
use Laravel\Horizon\Console\WorkCommand;
use Throwable;

/** Avoid formatting per-job status text which a quiet console will discard. */
class QuietAwareHorizonWorkCommand extends WorkCommand
{
    public static function fromOriginal(WorkCommand $command): self
    {
        // Keep Horizon's explicit worker/cache injection, including decorated dependencies.
        return new self($command->worker, $command->cache);
    }

    protected function writeOutput(Job $job, $status, ?Throwable $exception = null)
    {
        if ($this->output->isQuiet()) {
            // Preserve the display timer if an output adapter changes verbosity later.
            if ($status === 'starting') $this->latestStartedAt = microtime(true);
            return;
        }

        parent::writeOutput($job, $status, $exception);
    }
}
