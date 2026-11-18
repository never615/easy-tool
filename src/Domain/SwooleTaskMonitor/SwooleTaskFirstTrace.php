<?php

namespace Mallto\Tool\Domain\SwooleTaskMonitor;

use Closure;
use Throwable;

/**
 * 首个真实 Task 的诊断证据，默认关闭且数量受限。仅采样诊断记录，不采样入口流量。
 * 不记录 Redis 解析、命令、定时器、负载、主题、任务 ID 或异常文本。
 */
final class SwooleTaskFirstTrace
{
    private const CONNECTION_NAMES = ['default', 'local_cache', 'cache', 'queue', 'horizon',
        'task-monitor', 'task_monitor', 'task_monitor_isolated'];
    private static ?array $worker = null;
    private static ?self $current = null;
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

    /** 仅使用传输后的标量调用，入口采样先于本类自动加载。 */
    public static function start(array $context, array $entry, int $workerCall, bool $workerFallback = false,
        array $transportPoints = []): ?self
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
                'submitted_at_ns' => $context['submitted_at_ns'],
                'context_origin' => $workerFallback ? 'worker_entry_fallback' : 'producer'];
            $trace = new self($identity, self::sample(...), self::write(...));
            $trace->points['entry'] = $entry;
            foreach ($transportPoints as $name => $at) {
                if (in_array($name, ['submitted', 'send_before', 'pipe_received'], true)
                    && is_int($at) && $at >= 0) {
                    $trace->points[$name] = ['wall_ns' => $at];
                }
            }
            $trace->captureNs = $entry['sample_ns'] ?? 0;
            return $trace;
        } catch (Throwable) {
            return null; // 记录缺失会使覆盖无效，绝不能伪造成零开销。
        }
    }

    public static function setCurrent(?self $trace): void
    {
        self::$current = $trace;
    }

    /** 仅由已启用的业务阶段诊断调用。 */
    public static function checkpointCurrent(string $name): void
    {
        if (self::$current !== null && in_array($name, ['cache_first_put_start', 'cache_first_put_end',
            'parser_resolve_start', 'parser_resolve_end', 'parse_floor_end', 'parse_lookup_end',
            'parse_coordinate_end'], true)) {
            self::$current->checkpoint($name);
        }
    }

    /** 仅查看现有管理器的连接名；connections() 不会打开连接。 */
    private static function sample(): array
    {
        $point = self::entryPoint();
        $point['redis_connections'] = null;
        // Laravel 11 的容器未公开无需解析即可获取实例的方法。
        self::$instancesProperty ??= new \ReflectionProperty(\Illuminate\Container\Container::class, 'instances');
        $manager = self::$instancesProperty->getValue(app())['redis'] ?? null;
        if (is_object($manager) && method_exists($manager, 'connections')) {
            // Laravel 11 在首次解析连接前，将连接集合保留为 null。
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

    /** 调用方也会在本类加载前内联使用，必须保持两处结构一致。 */
    private static function entryPoint(): array
    {
        return ['wall_ns' => hrtime(true), 'usage' => function_exists('getrusage') ? getrusage() : null,
            'files' => count(get_included_files()), 'classes' => count(get_declared_classes()),
            'memory_bytes' => memory_get_usage(true)];
    }

    public function checkpoint(string $name): void
    {
        if (!in_array($name, ['before_monitor_started', 'before_business', 'after_business', 'after_terminal',
            'cache_first_put_start', 'cache_first_put_end', 'parser_resolve_start', 'parser_resolve_end',
            'parse_floor_end', 'parse_lookup_end', 'parse_coordinate_end'], true)) {
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
            // 文件系统或采样器故障不能覆盖原始业务结果或 Throwable。
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
            // 入口刻意不检查 Redis：此时记录器类尚未加载。
            $connections = $point['redis_connections'] ?? null;
            $clean['redis_connections'] = is_array($connections) ? [
                'count' => is_int($connections['count'] ?? null) ? $connections['count'] : null,
                'allowlisted_names' => array_values(array_intersect(
                    self::CONNECTION_NAMES,
                    (array)($connections['allowlisted_names'] ?? []))),
            ] : null;
            $points[$name] = $clean;
        }
        foreach (['submitted', 'send_before', 'pipe_received'] as $name) {
            $point = $this->points[$name] ?? null;
            $points[$name] = is_array($point) && is_int($point['wall_ns'] ?? null)
                ? ['mono_ns' => $point['wall_ns']] : null;
        }
        $entryAt = $this->points['entry']['wall_ns'] ?? null;
        $points['handle_entry'] = is_int($entryAt) ? ['mono_ns' => $entryAt] : null;
        return $points;
    }

    /** 每代、每通道、每序号独占一个文件：工作进程间不共享追加锁。 */
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
        // 通过原子目录占用将替代进程限制在 2048 代以内，无需共享追加锁。
        // 准入在终态监控后执行，其 I/O 计入独立写入器耗时。
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
        // 独立计时排除旁路文件自身的写入；旁路文件缺失属于诊断失败。
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
