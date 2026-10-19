<?php

namespace Mallto\Tool\Domain\RuntimeConfig;

use Throwable;

/** Local, bounded diagnostic evidence; never participates in cache correctness. */
final class RuntimeConfigRefillTrace
{
    private const MAX_RECORDS_PER_PROCESS = 256;
    private static int $pid = 0;
    private static int $started = 0;
    private int $lockMisses = 0;
    private int $sleepCount = 0;
    private int $sleepNs = 0;
    private int $loaderCount = 0;
    private int $loaderNs = 0;

    private function __construct(
        private readonly string $runId,
        private readonly string $directory,
        private readonly string $fingerprint,
        private readonly int $processId,
        private readonly int $sequence,
        private readonly float $epoch,
        private readonly int $startedNs
    ) {
    }

    public static function start(RuntimeConfigIdentity $identity): ?self
    {
        try {
            if (!config('runtime_config.refill_trace_enabled', false)) {
                return null;
            }
            $runId = (string)config('runtime_config.refill_trace_run_id', '');
            $directory = (string)config('runtime_config.refill_trace_directory', '');
            if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,95}\z/D', $runId)
                || !str_starts_with($directory, '/') || str_contains($directory, "\0")
                || !is_dir($directory) || !is_writable($directory)) {
                return null;
            }
            $pid = getmypid();
            // A pre-fork parent may have used the same class during startup.
            if (self::$pid !== $pid) {
                self::$pid = $pid;
                self::$started = 0;
            }
            if (self::$started >= self::MAX_RECORDS_PER_PROCESS) {
                return null;
            }
            return new self($runId, rtrim($directory, '/'), $identity->fingerprint(),
                $pid, ++self::$started, microtime(true), hrtime(true));
        } catch (Throwable) {
            return null;
        }
    }

    public function lockMiss(): void
    {
        $this->lockMisses++;
    }

    public function begin(): int
    {
        return hrtime(true);
    }

    public function slept(int $startedNs): void
    {
        $this->sleepCount++;
        $this->sleepNs += max(0, hrtime(true) - $startedNs);
    }

    public function loaded(int $startedNs): void
    {
        $this->loaderCount++;
        $this->loaderNs += max(0, hrtime(true) - $startedNs);
    }

    public function finish(string $outcome): void
    {
        try {
            $endedNs = hrtime(true);
            $row = ['schema_version' => 1, 'run_id' => $this->runId,
                'identity_fingerprint' => $this->fingerprint, 'pid' => $this->processId,
                'sequence' => $this->sequence, 'limit_per_process' => self::MAX_RECORDS_PER_PROCESS,
                'started_at' => $this->epoch, 'ended_at' => microtime(true),
                'started_monotonic_ns' => $this->startedNs, 'ended_monotonic_ns' => $endedNs,
                'total_ns' => max(0, $endedNs - $this->startedNs),
                'lock_misses' => $this->lockMisses, 'sleep_count' => $this->sleepCount,
                'sleep_ns' => $this->sleepNs, 'loader_count' => $this->loaderCount,
                'loader_ns' => $this->loaderNs, 'outcome' => $outcome];
            // File I/O is outside total_ns, but remains real diagnostic overhead.
            // A process reaching sequence=256 has truncated subsequent evidence.
            @file_put_contents($this->directory . '/' . $this->runId . '-' . $this->processId . '.jsonl',
                json_encode($row, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Diagnostics must not change a value, retry a loader or mask its exception.
        }
    }
}
