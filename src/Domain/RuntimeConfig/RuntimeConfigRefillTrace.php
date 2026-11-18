<?php

namespace Mallto\Tool\Domain\RuntimeConfig;

use Throwable;

/** 数量受限的本地诊断证据，绝不参与缓存正确性判断。 */
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
            // 派生子进程前，父进程可能已在启动阶段使用过同一个类。
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
            // 文件 I/O 不计入 total_ns，但仍属于实际诊断开销。
            // 进程序号达到 sequence=256 时，后续证据将被截断。
            @file_put_contents($this->directory . '/' . $this->runId . '-' . $this->processId . '.jsonl',
                json_encode($row, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // 诊断不得修改值、重试加载器或掩盖其异常。
        }
    }
}
