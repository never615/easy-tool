<?php

namespace Mallto\Tool\Domain\SwooleTaskMonitor;

use Closure;
use Throwable;

/**
 * Bounded, default-off first actual Task evidence. This samples diagnostic records, never ingress.
 * No Redis resolution, command, timer, payload, topic, task ID or exception text is recorded.
 */
final class SwooleTaskFirstTrace
{
    private const CONNECTION_NAMES = ['default', 'local_cache', 'cache', 'queue', 'horizon',
        'task-monitor', 'task_monitor', 'task_monitor_isolated'];
    private static ?array $worker = null;
    private static ?\ReflectionProperty $instancesProperty = null;
    private array $points = [];
    private int $captureNs = 0;
    private int $captureFailures = 0;

    public function __construct(
        private readonly array $identity,
        private readonly Closure $sampler,
        private readonly Closure $writer
    ) {
    }

    /** Called only with transported scalars, after an entry sample taken before this class autoloads. */
    public static function start(array $context, array $entry, int $workerCall): ?self
    {
        try {
            if (config('swoole_task_monitor.first_trace.enabled', false) !== true
                || config('swoole_task_monitor.first_trace.run_id') !== ($context['run_id'] ?? null)
                || !SwooleTaskExecutionDiagnostics::validRun($context['run_id'] ?? null)
                || !in_array($context['channel'] ?? null, ['location', 'diy'], true)
                || !is_int($context['submitted_at_ns'] ?? null)) {
                return null;
            }
            $pid = getmypid();
            $run = $context['run_id'];
            if (self::$worker === null || self::$worker['pid'] !== $pid || self::$worker['run_id'] !== $run) {
                self::$worker = ['pid' => $pid, 'run_id' => $run, 'generation' => bin2hex(random_bytes(16)),
                    'slot' => null, 'channels' => ['location' => 0, 'diy' => 0]];
            }
            $channel = $context['channel'];
            $ordinal = ++self::$worker['channels'][$channel];
            $limit = max(1, min(2, (int)config('swoole_task_monitor.first_trace.per_channel', 2)));
            if ($ordinal > $limit) {
                return null;
            }
            $identity = ['schema_version' => 1, 'run_id' => $run, 'pid' => $pid,
                'generation' => self::$worker['generation'], 'channel' => $channel,
                'eligible_worker_call' => $workerCall, 'channel_call' => $ordinal,
                'submitted_at_ns' => $context['submitted_at_ns']];
            $trace = new self($identity, self::sample(...), self::write(...));
            $trace->points['entry'] = $entry;
            $trace->captureNs = $entry['sample_ns'] ?? 0;
            return $trace;
        } catch (Throwable) {
            return null; // Missing records invalidate coverage; they are never fabricated as zero cost.
        }
    }

    /** The existing manager's names only; connections() does not open a connection. */
    private static function sample(): array
    {
        $point = self::entryPoint();
        $point['redis_connections'] = null;
        // Container exposes no public non-resolving instance getter in Laravel 11.
        self::$instancesProperty ??= new \ReflectionProperty(\Illuminate\Container\Container::class, 'instances');
        $manager = self::$instancesProperty->getValue(app())['redis'] ?? null;
        if (is_object($manager) && method_exists($manager, 'connections')) {
            // Laravel 11 leaves connections null until its first connection is resolved.
            $connections = $manager->connections();
            if ($connections !== null && !is_array($connections)) {
                throw new \UnexpectedValueException('Invalid existing Redis connections');
            }
            $names = array_keys($connections ?? []);
            $point['redis_connections'] = ['count' => count($names),
                'allowlisted_names' => array_values(array_intersect(self::CONNECTION_NAMES, $names))];
        }
        return $point;
    }

    /** Also used inline by the caller before this class loads; keep the two schemas identical. */
    private static function entryPoint(): array
    {
        return ['wall_ns' => hrtime(true), 'usage' => function_exists('getrusage') ? getrusage() : null,
            'files' => count(get_included_files()), 'classes' => count(get_declared_classes()),
            'memory_bytes' => memory_get_usage(true)];
    }

    public function checkpoint(string $name): void
    {
        if (!in_array($name, ['before_monitor_started', 'before_business', 'after_business', 'after_terminal'], true)) {
            return;
        }
        $before = hrtime(true);
        try {
            $point = ($this->sampler)();
            if (!is_array($point) || !is_int($point['wall_ns'] ?? null)) {
                throw new \RuntimeException('Invalid trace point');
            }
            $point['sample_ns'] = hrtime(true) - $before;
            $this->points[$name] = $point;
        } catch (Throwable) {
            $this->points[$name] = null;
            $this->captureFailures++;
        } finally {
            $this->captureNs += hrtime(true) - $before;
        }
    }

    public function finish(string $outcome): void
    {
        try {
            if (!in_array($outcome, ['finished', 'rejected', 'failed'], true)) {
                return;
            }
            ($this->writer)($this->identity + ['outcome' => $outcome, 'capture_ns' => $this->captureNs,
                'capture_failures' => $this->captureFailures, 'points' => $this->sanitizedPoints()]);
        } catch (Throwable) {
            // A filesystem or sampler failure must not replace the original business result/Throwable.
        }
    }

    private function sanitizedPoints(): array
    {
        $points = [];
        $usageKeys = ['ru_utime.tv_sec', 'ru_utime.tv_usec', 'ru_stime.tv_sec', 'ru_stime.tv_usec',
            'ru_minflt', 'ru_majflt', 'ru_nvcsw', 'ru_nivcsw'];
        foreach ($this->points as $name => $point) {
            if (!is_array($point)) {
                $points[$name] = null;
                continue;
            }
            $clean = [];
            foreach (['wall_ns', 'files', 'classes', 'memory_bytes', 'sample_ns'] as $key) {
                $clean[$key] = is_int($point[$key] ?? null) && $point[$key] >= 0 ? $point[$key] : null;
            }
            $clean['usage'] = [];
            foreach ($usageKeys as $key) {
                $value = $point['usage'][$key] ?? null;
                $clean['usage'][$key] = is_int($value) && $value >= 0 ? $value : null;
            }
            // Entry deliberately has no Redis inspection: it precedes recorder class loading.
            $connections = $point['redis_connections'] ?? null;
            $clean['redis_connections'] = is_array($connections) ? [
                'count' => is_int($connections['count'] ?? null) ? $connections['count'] : null,
                'allowlisted_names' => array_values(array_intersect(
                    self::CONNECTION_NAMES,
                    (array)($connections['allowlisted_names'] ?? []))),
            ] : null;
            $points[$name] = $clean;
        }
        return $points;
    }

    /** One exclusive file per generation/channel/ordinal: no shared append lock across workers. */
    private static function write(array $record): void
    {
        $before = hrtime(true);
        $encoded = json_encode($record, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 16384) {
            return;
        }
        $encodedAt = hrtime(true);
        $root = storage_path('logs/task-first-trace');
        $directory = $root . '/' . $record['run_id'];
        foreach ([$root, $directory] as $path) {
            if (is_link($path) || (!is_dir($path) && !@mkdir($path, 0700) && !is_dir($path))) {
                return;
            }
        }
        // Atomic directory claims bound replacements to 2048 generations without a shared append lock.
        // Admission runs after terminal monitoring; its I/O is included in the separate writer timing.
        if (self::$worker['slot'] === null) {
            self::$worker['slot'] = false;
            for ($attempt = 0; $attempt < 2048; $attempt++) {
                $slot = ($record['pid'] + $attempt) % 2048;
                if (@mkdir($directory . '/slot-' . $slot, 0700)) {
                    self::$worker['slot'] = $slot;
                    break;
                }
            }
        }
        if (self::$worker['slot'] === false) {
            return;
        }
        $path = $directory . '/slot-' . self::$worker['slot'] . '/' . $record['pid'] . '-' . $record['generation'] . '-'
            . $record['channel'] . '-' . $record['channel_call'];
        $handle = @fopen($path . '.json', 'xb');
        if ($handle === false) {
            return;
        }
        try {
            @chmod($path . '.json', 0600);
            $written = fwrite($handle, $encoded . "\n");
        } finally {
            fclose($handle);
        }
        $endedAt = hrtime(true);
        // Separate timing excludes this sidecar's own write; a missing sidecar is a diagnostic failure.
        $timing = json_encode(['bytes' => $written, 'encode_ns' => $encodedAt - $before,
            'write_ns' => $endedAt - $encodedAt], JSON_THROW_ON_ERROR);
        $sidecar = @fopen($path . '.io.json', 'xb');
        if ($sidecar !== false) {
            @chmod($path . '.io.json', 0600);
            try {
                fwrite($sidecar, $timing . "\n");
            } finally {
                fclose($sidecar);
            }
        }
    }
}
