<?php

namespace Mallto\Tool\Domain\Queue;

use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;

final class SharedHorizonWorker extends Worker
{
    private int $cursor = 0;
    private SharedHorizonQueue $sharedQueue;
    private array $pollSlots;

    public static function fromOriginal(Worker $worker, SharedHorizonQueue $connection, string $queues): self
    {
        $shared = new self($worker->manager, $worker->events, $worker->exceptions,
            $worker->isDownForMaintenance, $worker->resetScope);
        $shared->sharedQueue = $connection;
        $shared->pollSlots = explode(',', $queues);
        // The first queue gets two consecutive turns. Every other ready queue
        // still advances on each cycle, including during a sustained priority backlog.
        array_unshift($shared->pollSlots, $shared->pollSlots[0]);

        return $shared;
    }

    protected function getNextJob($connection, $queue)
    {
        $slots = array_merge(array_slice($this->pollSlots, $this->cursor), array_slice($this->pollSlots, 0, $this->cursor));
        $job = parent::getNextJob($connection, implode(',', array_unique($slots)));
        if ($job) {
            $slot = array_search($job->getQueue(), $slots, true);
            $this->cursor = ($this->cursor + $slot + 1) % count($this->pollSlots);
        }
        return $job;
    }

    protected function timeoutForJob($job, WorkerOptions $options)
    {
        if (!$job) {
            return $options->timeout;
        }

        $route = $this->sharedQueue->optionsFor($job->getQueue());
        return $job->timeout() ?? $route['timeout'] ?? $options->timeout;
    }

    protected function registerTimeoutHandler($job, WorkerOptions $options)
    {
        parent::registerTimeoutHandler($job, $job ? $this->optionsForJob($job, $options) : $options);
    }

    public function process($connectionName, $job, WorkerOptions $options)
    {
        return parent::process($job->getConnectionName(), $job, $this->optionsForJob($job, $options));
    }

    private function optionsForJob($job, WorkerOptions $options): WorkerOptions
    {
        $route = $this->sharedQueue->optionsFor($job->getQueue());
        if (isset($route['tries'])) {
            $options = clone $options;
            $options->maxTries = $route['tries'];
        }

        return $options;
    }
}
