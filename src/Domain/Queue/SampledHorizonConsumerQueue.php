<?php

namespace Mallto\Tool\Domain\Queue;

use Laravel\Horizon\Events\JobDeleted;
use Laravel\Horizon\Events\JobReserved;
use Throwable;

/** Used only in isolated acceptance to split queue transport from Horizon bookkeeping. */
final class SampledHorizonConsumerQueue extends BoundedHorizonRedisQueue
{
    private const STRIDE = 512;
    private const LIMIT = 256;

    private array $calls = [];
    private array $written = [];
    private ?array $sample = null;
    private bool $retrieving = false;

    public function pop($queue = null, $index = 0)
    {
        if (!$this->start($queue, 'pop')) return parent::pop($queue, $index);
        $outcome = 'error';
        try {
            $job = parent::pop($queue, $index);
            $outcome = $job === null ? 'empty' : 'reserved';
            return $job;
        } finally {
            $this->finish($outcome);
        }
    }

    public function deleteReserved($queue, $job)
    {
        if (!$this->start($queue, 'delete')) {
            parent::deleteReserved($queue, $job);
            return;
        }
        $outcome = 'error';
        try {
            parent::deleteReserved($queue, $job);
            $outcome = $job->hasFailed() ? 'failed_job' : 'returned';
        } finally {
            $this->finish($outcome);
        }
    }

    protected function migrate($queue)
    {
        $start = $this->sample === null ? null : hrtime(true);
        try {
            parent::migrate($queue);
        } finally {
            $this->record('migrate', $start);
        }
    }

    protected function retrieveNextJob($queue, $block = true)
    {
        if ($this->sample === null || $this->retrieving) return parent::retrieveNextJob($queue, $block);
        $this->retrieving = true;
        $start = hrtime(true);
        try {
            return parent::retrieveNextJob($queue, $block);
        } finally {
            $this->retrieving = false;
            $this->record('retrieve', $start);
        }
    }

    protected function event($queue, $event)
    {
        $stage = match (true) {
            $event instanceof JobReserved => 'reserved_event',
            $event instanceof JobDeleted => 'deleted_event',
            default => null,
        };
        $start = $stage !== null && $this->sample !== null ? hrtime(true) : null;
        try {
            parent::event($queue, $event);
        } finally {
            if ($stage !== null) $this->record($stage, $start);
        }
    }

    private function start($queue, string $operation): bool
    {
        if ($this->sample !== null || !in_array($queue, [self::LOCATOR_HANDOFF_QUEUE, 'pgsql-write'], true)) return false;
        $key = $operation.':'.$queue;
        if (($this->written[$key] ?? 0) >= self::LIMIT) return false;
        $ordinal = $this->calls[$key] = ($this->calls[$key] ?? 0) + 1;
        $pid = getmypid();
        if ($ordinal > 2 && ($ordinal + $pid) % self::STRIDE !== 0) return false;
        $this->written[$key] = ($this->written[$key] ?? 0) + 1;
        $this->sample = ['pid' => $pid, 'queue' => $queue, 'operation' => $operation, 'ordinal' => $ordinal,
            'start_ns' => hrtime(true), 'stages_ns' => []];
        return true;
    }

    private function record(string $name, ?int $started): void
    {
        if ($started === null) return;
        $stages = &$this->sample['stages_ns'];
        $stages[$name] = ($stages[$name] ?? 0) + hrtime(true) - $started;
    }

    private function finish(string $outcome): void
    {
        $end = hrtime(true);
        $sample = $this->sample;
        $this->sample = null;
        try {
            $sample['duration_ns'] = $end - $sample['start_ns'];
            $sample['outcome'] = $outcome;
            $sample['run_id'] = config('location.alarm_event_trace.run_id');
            $directory = config('location.alarm_event_trace.directory');
            if (!is_string($directory) || $directory === '' || !is_dir($directory)) return;
            @file_put_contents($directory.'/consumer-queue-'.$sample['pid'].'.ndjson',
                json_encode($sample, JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
        } catch (Throwable) {
            // Trace writes cannot change the queue outcome.
        }
    }
}
