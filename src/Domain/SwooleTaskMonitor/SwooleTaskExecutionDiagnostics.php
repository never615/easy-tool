<?php

namespace Mallto\Tool\Domain\SwooleTaskMonitor;

use Closure;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * UUID-scoped process-local execution timings. No payloads, topics, coordinates or task IDs.
 * submit_to_worker includes both IPC hops and their waits; it is not pure Task queue time.
 * A worker heartbeat persists the final partial second after traffic stops.
 */
final class SwooleTaskExecutionDiagnostics
{
    // Fixed bound covers a 512-worker pool plus replacements without unbounded Redis growth.
    public const MAX_GENERATIONS = 2048;
    public const STAGES = ['submit_to_worker', 'monitor_started', 'handle_task', 'monitor_terminal', 'worker_total'];
    public const BUCKET_LIMITS_NS = [100000, 1000000, 5000000, 20000000, 100000000];
    public const CHANNELS = [
        'Mallto\\Location\\Location\\UploadData\\Task\\LanceLocationDataParseTask' => 'location',
        'Mallto\\Location\\Location\\UploadData\\Task\\LanceDiyDataUploadParseTask' => 'diy',
    ];
    private static ?self $worker = null;
    private array $channels;
    private int $failures = 0;
    private int $monitorFailures = 0;
    private float $lastFlushAt = -INF;

    public function __construct(
        private readonly string $runId,
        private readonly string $generation,
        private readonly int $pid,
        private readonly Closure $writer,
        private readonly Closure $wallClock,
        private readonly ?Closure $monoClock = null
    ) {
        $stages = array_fill_keys(self::STAGES,
            ['count' => 0, 'total_ns' => 0, 'max_ns' => 0, 'buckets' => array_fill(0, 6, 0)]);
        $this->channels = array_fill_keys(['location', 'diy'],
            ['started' => 0, 'finished' => 0, 'rejected' => 0, 'failed' => 0, 'stages' => $stages]);
    }

    public static function validRun(mixed $run): bool
    {
        return is_string($run) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $run) === 1;
    }

    /** Lazy in the actual Task worker, never create a connection or timer on the producer. */
    public static function start(string $runId): ?self
    {
        try {
            if (config('swoole_task_monitor.execution_diagnostics.enabled', false) !== true
                || config('swoole_task_monitor.execution_diagnostics.run_id') !== $runId || !self::validRun($runId)) {
                return null;
            }
            if (self::$worker !== null && self::$worker->pid === getmypid() && self::$worker->runId === $runId) {
                return self::$worker;
            }
            $generation = bin2hex(random_bytes(16));
            $diagnostics = new self($runId, $generation, getmypid(),
                static function (array $snapshot) use ($runId, $generation): void {
                    $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);
                    if (strlen($encoded) > 16384) {
                        throw new \RuntimeException('Execution diagnostic record exceeds bound');
                    }
                    $accepted = Redis::connection(config('swoole_task_monitor.redis_connection') ?: 'default')->eval(
                        "if redis.call('HEXISTS',KEYS[1],ARGV[1])==0 and redis.call('HLEN',KEYS[1])>=ARGV[3]+0 then return 0 end "
                        . "redis.call('HSET',KEYS[1],ARGV[1],ARGV[2]);redis.call('EXPIRE',KEYS[1],3600);return 1",
                        1, 'swoole_task_execution:' . $runId, $generation, $encoded, self::MAX_GENERATIONS);
                    if ((int)$accepted !== 1) {
                        throw new \RuntimeException('Execution diagnostic generation bound reached');
                    }
                }, static fn(): float => microtime(true));
            self::$worker = $diagnostics;
            // A missing/failed heartbeat is a health failure, not permission to fabricate a final count.
            try {
                if (!class_exists(\Swoole\Timer::class)) {
                    throw new \RuntimeException('Execution diagnostic heartbeat unavailable');
                }
                $timerId = \Swoole\Timer::tick(1000,
                    static function () use ($diagnostics): void { $diagnostics->flush(); });
                if (!is_int($timerId) || $timerId <= 0) {
                    throw new \RuntimeException('Execution diagnostic heartbeat unavailable');
                }
            } catch (Throwable) {
                $diagnostics->failures++;
            }
            return $diagnostics;
        } catch (Throwable) {
            return null; // Missing final stage coverage makes the independent validator reject the evidence.
        }
    }

    public function begin(): ?int
    {
        try {
            $now = $this->monoClock !== null ? ($this->monoClock)() : hrtime(true);
            if (!is_int($now) || $now < 0) {
                throw new \RuntimeException('Invalid execution diagnostic clock');
            }
            return $now;
        } catch (Throwable) {
            $this->failures++;
            return null;
        }
    }

    /** Record one completed synchronous handle; monitorFailures is this handle's delta, not a cumulative value. */
    public function record(string $channel, string $outcome, array $durations, int $monitorFailures = 0): void
    {
        if (!isset($this->channels[$channel]) || !in_array($outcome, ['finished', 'rejected', 'failed'], true)) {
            $this->failures++;
            return;
        }
        $this->channels[$channel]['started']++;
        $this->channels[$channel][$outcome]++;
        $this->monitorFailures += max(0, $monitorFailures);
        if ($monitorFailures < 0 || array_diff(array_keys($durations), self::STAGES) !== []) {
            $this->failures++;
        }
        foreach (self::STAGES as $name) {
            $ns = $durations[$name] ?? null;
            $stage = &$this->channels[$channel]['stages'][$name];
            if (!is_int($ns) || $ns < 0 || $stage['count'] === PHP_INT_MAX
                || $ns > PHP_INT_MAX - $stage['total_ns']) {
                $this->failures++;
                unset($stage);
                continue;
            }
            $bucket = 0;
            foreach (self::BUCKET_LIMITS_NS as $limit) {
                if ($ns <= $limit) {
                    break;
                }
                $bucket++;
            }
            $stage['count']++;
            $stage['total_ns'] += $ns;
            $stage['max_ns'] = max($stage['max_ns'], $ns);
            $stage['buckets'][$bucket]++;
            unset($stage);
        }
    }

    public function snapshot(): array
    {
        return ['schema_version' => 1, 'run_id' => $this->runId, 'generation' => $this->generation,
            'pid' => $this->pid, 'epoch' => ($this->wallClock)(), 'failures' => $this->failures,
            'monitor_failures' => $this->monitorFailures, 'channels' => $this->channels];
    }

    public function flush(bool $force = false): void
    {
        try {
            $now = ($this->wallClock)();
            if (!$force && $now - $this->lastFlushAt < 1) {
                return;
            }
            $this->lastFlushAt = $now; // Throttle failures too; retry absolute snapshots without double-counting.
            ($this->writer)($this->snapshot());
        } catch (Throwable) {
            $this->failures++;
        }
    }
}
