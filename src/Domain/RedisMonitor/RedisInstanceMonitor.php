<?php

namespace Mallto\Tool\Domain\RedisMonitor;

use Closure;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/** Fixed-cost sampling; all instance baselines and failures share one atomic snapshot. */
final class RedisInstanceMonitor
{
    private readonly Closure $clock;

    public function __construct(
        private readonly Factory $probes,
        private readonly Factory $stateRedis,
        private readonly LoggerInterface $logger,
        private readonly array $config,
        private readonly array $targets,
        ?Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function run(): array
    {
        try {
            $state = $this->stateRedis->connection($this->config['state_connection'] ?? $this->config['connection']);
            $key = $this->config['instances_state_key'] ?? $this->config['state_key'];
            $previous = json_decode((string)$state->get($key), true);
            $previous = is_array($previous) ? $previous : [];
            $previous = ($previous['schema_version'] ?? null) === 3 ? $previous : [];
            $instances = [];
            foreach ($this->targets as $target) {
                $id = $target['id'];
                $old = $previous['instances'][$id] ?? [];
                $instances[$id] = $this->sample($target, is_array($old) ? $old : []);
            }
            $now = ($this->clock)();
            $payload = ['schema_version' => 3, 'last_sampled_at' => $now, 'instances' => $instances];
            $state->setex($key, max(120, (int)($this->config['state_ttl_seconds'] ?? 86400)),
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            $failed = count(array_filter($instances, static fn (array $row): bool => in_array('sampling_failed', $row['reasons'], true)));
            return ['enabled' => true, 'ok' => $failed === 0, 'failed_instances' => $failed,
                'level' => $this->worst(array_column($instances, 'level')), 'instances' => array_values($instances)];
        } catch (Throwable $exception) {
            $this->safeLog('error', ['reason' => 'state_failed', 'exception_class' => get_class($exception)]);
            return ['enabled' => true, 'ok' => false, 'level' => 'error', 'reason' => 'state_failed'];
        }
    }

    private function sample(array $target, array $old): array
    {
        $old['baseline'] = is_array($old['baseline'] ?? null) ? $old['baseline'] : [];
        $old['snapshot'] = is_array($old['snapshot'] ?? null) ? $old['snapshot'] : [];
        $row = ['id' => $target['id'], 'connection' => $target['connection'], 'roles' => $target['roles'],
            'last_sampled_at' => ($this->clock)(),
            'last_logged_at' => is_numeric($old['last_logged_at'] ?? null) ? (float)$old['last_logged_at'] : 0];
        $connection = null;
        $stage = 'connection';
        try {
            if ($target['profile'] === []) {
                throw new \InvalidArgumentException('Redis monitor target profile invalid');
            }
            $connection = $this->probes->connection($target['id']);
            if ($connection instanceof PhpRedisConnection) {
                // Older phpredis versions require a connected socket before setting this option.
                $stage = 'probe_options';
                $connection->client()->setOption(\Redis::OPT_MAX_RETRIES, 0);
            }
            $stage = 'ping';
            $start = hrtime(true);
            $connection->command('PING');
            $ping = round((hrtime(true) - $start) / 1e6, 3);
            $info = [];
            $limited = [];
            foreach (['memory', 'stats', 'server', 'cpu', 'clients'] as $section) {
                $stage = 'info_'.$section;
                try {
                    $info[$section] = $this->normalize($connection->command('INFO', [$section]));
                } catch (Throwable $exception) {
                    // A timed-out socket may contain an unread reply; never reuse it for more INFO.
                    if (!str_contains(strtolower($exception->getMessage()), 'noperm')) {
                        throw $exception;
                    }
                    $info[$section] = [];
                    $limited[] = $section;
                }
            }
            $now = ($this->clock)();
            $row['last_sampled_at'] = $now;
            $stage = 'snapshot_build';
            $snapshot = RedisMemorySnapshot::fromInfo($info['memory'], $info['stats'])->toArray();
            $runId = isset($info['server']['run_id']) ? hash('sha256', (string)$info['server']['run_id']) : null;
            $baseline = ['run_id_hash' => $runId, 'at' => $now];
            foreach (['used_cpu_sys', 'used_cpu_user', 'used_cpu_sys_main_thread', 'used_cpu_user_main_thread'] as $field) {
                $baseline[$field] = $this->number($info['cpu'], $field);
            }
            $sameRun = $runId !== null && ($old['baseline']['run_id_hash'] ?? null) === $runId;
            $seconds = $now - (float)($old['baseline']['at'] ?? $now);
            $cpu = $this->cpu($baseline, $old['baseline'] ?? [], $sameRun, $seconds, '_main_thread');
            $processCpu = $this->cpu($baseline, $old['baseline'] ?? [], $sameRun, $seconds, '');
            $cpuStatus = $baseline['used_cpu_sys_main_thread'] === null || $baseline['used_cpu_user_main_thread'] === null
                ? 'unavailable' : ($cpu === null ? 'waiting_baseline' : 'available');
            $snapshot += ['main_thread_cpu_percent' => $cpu, 'process_cpu_percent' => $processCpu,
                'cpu_status' => $cpuStatus, 'ping_ms' => $ping];
            foreach (['instantaneous_ops_per_sec', 'instantaneous_input_kbps', 'instantaneous_output_kbps'] as $field) {
                $snapshot[$field] = $this->number($info['stats'], $field);
            }
            foreach (['connected_clients', 'blocked_clients'] as $field) {
                $snapshot[$field] = $this->number($info['clients'], $field);
            }
            foreach (['evicted_keys', 'rejected_connections'] as $field) {
                $before = $old['snapshot'][$field] ?? null;
                $snapshot[$field.'_delta'] = $sameRun && is_numeric($before) && $snapshot[$field] >= $before
                    ? $snapshot[$field] - $before : null;
            }
            $reasons = [];
            $level = 'normal';
            foreach ([
                'memory_usage' => [$snapshot['used_percent'], $this->config['warning_percent'] ?? 70, $this->config['error_percent'] ?? 90],
                'main_thread_cpu' => [$cpu, $this->config['cpu_warning_percent'] ?? 70, $this->config['cpu_error_percent'] ?? 90],
                'ping_latency' => [$ping, $this->config['ping_warning_ms'] ?? 50, $this->config['ping_error_ms'] ?? 90],
            ] as $reason => [$value, $warning, $error]) {
                $ceiling = $reason === 'ping_latency' ? PHP_FLOAT_MAX : 100.0;
                $warning = min($ceiling, max(1.0, (float)$warning));
                $error = min($ceiling, max(1.0, (float)$error));
                [$warning, $error] = [min($warning, $error), max($warning, $error)];
                if ($value !== null && $value >= $warning) {
                    $severity = $value >= $error ? 'error' : 'warning';
                    $level = $this->worst([$level, $severity]);
                    $reasons[] = $reason.'_'.$severity;
                }
            }
            if (($snapshot['evicted_keys_delta'] ?? 0) > 0 || ($snapshot['rejected_connections_delta'] ?? 0) > 0) {
                $level = 'error';
                $reasons[] = 'redis_counters_increased';
            }
            if ($limited !== [] || $cpuStatus === 'unavailable' || $runId === null
                || $snapshot['connected_clients'] === null || $snapshot['instantaneous_ops_per_sec'] === null) {
                $level = $this->worst([$level, 'warning']);
                $reasons[] = 'metrics_limited';
            }
            $row += ['level' => $level, 'reasons' => $reasons, 'snapshot' => $snapshot, 'baseline' => $baseline];
        } catch (Throwable $exception) {
            $row += ['level' => 'error', 'reasons' => ['sampling_failed'], 'snapshot' => [],
                'error_kind' => get_class($exception), 'error_stage' => $stage];
        } finally {
            try {
                $connection?->disconnect();
                if (method_exists($this->probes, 'purge')) {
                    $this->probes->purge($target['id']);
                }
            } catch (Throwable) {
                // A failed probe cleanup must not suppress other instances.
            }
        }
        $changed = ($old['level'] ?? 'normal') !== $row['level'] || ($old['reasons'] ?? []) !== $row['reasons'];
        if ($changed || ($row['level'] !== 'normal' && $row['last_sampled_at'] - $row['last_logged_at'] >= max(1, (int)($this->config['log_cooldown_seconds'] ?? 600)))) {
            $this->safeLog($row['level'] === 'normal' ? 'info' : $row['level'], [
                'instance' => $row['id'], 'connection' => $row['connection'], 'reasons' => $row['reasons'],
                // Fixed stages and exception classes identify failures without exposing client messages.
                'error_stage' => $row['error_stage'] ?? null, 'error_kind' => $row['error_kind'] ?? null,
            ]);
            $row['last_logged_at'] = $row['last_sampled_at'];
        }
        return $row;
    }

    private function cpu(array $now, array $old, bool $sameRun, float $seconds, string $suffix): ?float
    {
        if (!$sameRun || $seconds <= 0) {
            return null;
        }
        $delta = 0.0;
        foreach (['used_cpu_sys', 'used_cpu_user'] as $field) {
            $key = $field.$suffix;
            if (!is_numeric($now[$key] ?? null) || !is_numeric($old[$key] ?? null) || $now[$key] < $old[$key]) {
                return null;
            }
            $delta += $now[$key] - $old[$key];
        }
        return round($delta / $seconds * 100, 2);
    }

    private function number(array $info, string $key): ?float
    {
        return isset($info[$key]) && is_numeric($info[$key]) && is_finite((float)$info[$key])
            ? max(0.0, (float)$info[$key]) : null;
    }

    private function normalize(mixed $raw): array
    {
        if (is_string($raw)) {
            $flat = [];
            foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
                if ($line !== '' && !str_starts_with($line, '#') && str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $flat[trim($key)] = trim($value);
                }
            }
            return $flat;
        }
        $flat = [];
        foreach (is_array($raw) ? $raw : [] as $key => $value) {
            if (is_array($value)) {
                $flat = array_merge($flat, $this->normalize($value));
            } elseif (is_string($key)) {
                $flat[$key] = $value;
            }
        }
        return $flat;
    }

    private function worst(array $levels): string
    {
        return in_array('error', $levels, true) ? 'error' : (in_array('warning', $levels, true) ? 'warning' : 'normal');
    }

    private function safeLog(string $level, array $context): void
    {
        try {
            $this->logger->log($level, 'Redis instance monitor status', $context);
        } catch (Throwable) {
            // Monitoring logs cannot interrupt sampling.
        }
    }
}
