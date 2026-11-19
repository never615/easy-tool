<?php

namespace Mallto\Tool\Domain\SwooleTaskMonitor;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\PostgresConnection;
use Illuminate\Events\Dispatcher;
use PDO;
use ReflectionProperty;
use Throwable;
use WeakMap;

/**
 * Default-off, bounded Task observations. Never resolve a DB/event service or inspect SQL/bindings.
 * PDO metadata describes handles, not live sockets. QueryExecuted excludes failed/direct PDO SQL.
 */
final class SwooleTaskSqlDiagnostics
{
    private static ?self $current = null;
    private static ?array $worker = null;
    private static ?WeakMap $dispatchers = null;
    private static ?ReflectionProperty $instances = null;
    private bool $sampled = false;
    private bool $invalid = false;
    private int $count = 0;
    private float $queryMs = 0;
    private int $startedNs = 0;
    private array $before = [];
    private ?Dispatcher $dispatcher = null;
    private string $category = 'other';
    private int $ordinal = 0;

    private function __construct(private readonly ?self $parent) {}

    /** Even stride-skipped Tasks retain a scope so nested work cannot masquerade as a top-level Task. */
    public static function begin(string $taskClass): ?self
    {
        if (self::$current !== null) {
            self::$current->invalid = true;
            $scope = new self(self::$current);
            self::$current = $scope;
            return $scope;
        }
        $scope = null;
        try {
            if (config('swoole_task_monitor.sql_diagnostics.enabled', false) !== true
                || !in_array(config('app.env'), ['local', 'test', 'testing'], true)
                || self::inCoroutine()) {
                return null;
            }
            $run = config('swoole_task_monitor.sql_diagnostics.run_id');
            $expires = config('swoole_task_monitor.sql_diagnostics.expires_at');
            $now = time();
            if (!is_string($run)
                || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $run) !== 1
                || !is_int($expires) || $expires <= $now || $expires > $now + 120) {
                return null;
            }
            $pid = getmypid();
            if (self::$worker === null || self::$worker['pid'] !== $pid) {
                self::$worker = ['pid' => $pid, 'run' => $run, 'expires' => $expires,
                    'deadline_ns' => hrtime(true) + ($expires - $now) * 1_000_000_000,
                    'calls' => 0, 'samples' => 0, 'directory' => null, 'admitted' => false,
                    'generation' => bin2hex(random_bytes(16)), 'records' => []];
            }
            // A run/expiry change cannot extend a worker's window or reset its budget. Restart for a new run.
            if (self::$worker['run'] !== $run || !self::withinWindow()) {
                return null;
            }
            $scope = new self(null);
            self::$current = $scope;
            $call = ++self::$worker['calls'];
            $stride = max(1, min(1000000, (int)config('swoole_task_monitor.sql_diagnostics.sample_every', 100)));
            $limit = max(1, min(20, (int)config('swoole_task_monitor.sql_diagnostics.max_samples', 20)));
            if (($call - 1) % $stride !== 0 || self::$worker['samples'] >= $limit) {
                return $scope;
            }
            ++self::$worker['samples'];
            $scope->ordinal = $call;
            $scope->category = SwooleTaskExecutionDiagnostics::CHANNELS[$taskClass] ?? 'other';
            // Read only already-instantiated services; DB::listen() would create a default Connection.
            $instances = self::instances();
            $dispatcher = $instances['events'] ?? null;
            if (!$dispatcher instanceof Dispatcher || !self::admit()) {
                return $scope;
            }
            self::$dispatchers ??= new WeakMap();
            if (!isset(self::$dispatchers[$dispatcher])) {
                $dispatcher->listen(QueryExecuted::class, static function (QueryExecuted $event): void {
                    self::observe($event);
                });
                self::$dispatchers[$dispatcher] = true;
            }
            $scope->dispatcher = $dispatcher;
            $scope->before = self::snapshot($dispatcher);
            $scope->startedNs = hrtime(true);
            $scope->sampled = true;
            return $scope;
        } catch (Throwable) {
            // Retain any lightweight scope for its finally; diagnostic failures never block business.
            return $scope;
        }
    }

    private static function observe(QueryExecuted $event): void
    {
        $scope = self::$current;
        if ($scope === null || !$scope->sampled || $scope->invalid) {
            return;
        }
        try {
            if (self::inCoroutine() || !self::withinWindow()) {
                $scope->invalid = true;
                return;
            }
            $connection = $event->connection;
            if ($connection->pretending()) {
                return;
            }
            if (get_class($connection) !== PostgresConnection::class) {
                if ($connection->getDriverName() === 'pgsql') {
                    $scope->invalid = true;
                }
                return;
            }
            if ($connection->getEventDispatcher() !== $scope->dispatcher
                || !is_numeric($event->time) || !is_finite((float)$event->time) || $event->time < 0) {
                $scope->invalid = true;
                return;
            }
            ++$scope->count;
            $scope->queryMs += (float)$event->time;
        } catch (Throwable) {
            $scope->invalid = true; // A listener must never turn a completed query into a business failure.
        }
    }

    /** Clear attribution before any snapshots/writes, including when the original Task threw. */
    public function finish(bool $businessThrew): void
    {
        $ownsScope = self::$current === $this;
        self::$current = $ownsScope ? $this->parent : null;
        if (!$ownsScope || !$this->sampled) {
            return;
        }
        try {
            $endedNs = hrtime(true);
            $after = self::snapshot($this->dispatcher);
            $complete = !$this->invalid && self::withinWindow()
                && $this->before['status'] === 'ok' && $after['status'] === 'ok';
            $record = ['eligible_call' => $this->ordinal, 'category' => $this->category,
                'business_threw' => $businessThrew, 'complete' => $complete,
                'pg_successful_laravel_queries' => $complete ? $this->count : null,
                'pg_successful_laravel_query_ms' => $complete ? round($this->queryMs, 3) : null,
                'business_ms' => round(($endedNs - $this->startedNs) / 1000000, 3),
                'before' => $this->before, 'after' => $after];
            self::$worker['records'][] = $record;
            self::write();
        } catch (Throwable) {
            // Missing output is a missing observation, never a zero-query measurement.
        }
    }

    private static function inCoroutine(): bool
    {
        return class_exists('Swoole\\Coroutine', false) && \Swoole\Coroutine::getCid() >= 0;
    }

    private static function withinWindow(): bool
    {
        return self::$worker !== null && time() < self::$worker['expires']
            && hrtime(true) < self::$worker['deadline_ns']
            && config('swoole_task_monitor.sql_diagnostics.enabled', false) === true
            && config('swoole_task_monitor.sql_diagnostics.run_id') === self::$worker['run']
            && is_int(config('swoole_task_monitor.sql_diagnostics.expires_at'))
            && time() < config('swoole_task_monitor.sql_diagnostics.expires_at');
    }

    private static function instances(): array
    {
        self::$instances ??= new ReflectionProperty(Container::class, 'instances');
        return self::$instances->getValue(app());
    }

    private static function snapshot(Dispatcher $dispatcher): array
    {
        $manager = self::instances()['db'] ?? null;
        if ($manager === null) {
            return ['status' => 'manager_not_instantiated'];
        }
        if (get_class($manager) !== DatabaseManager::class) {
            return ['status' => 'unsupported_manager'];
        }
        $connections = $manager->getConnections();
        if (count($connections) > 16) {
            return ['status' => 'connection_overflow'];
        }
        $result = ['status' => 'ok', 'pg_connections' => 0, 'write_initialized' => 0,
            'write_lazy' => 0, 'write_null' => 0, 'read_initialized' => 0, 'read_lazy' => 0,
            'read_null' => 0, 'transactions_open' => 0];
        foreach ($connections as $connection) {
            if (get_class($connection) !== PostgresConnection::class) {
                // Custom PG implementations cannot be proven covered. Non-PG connections are irrelevant.
                if (!$connection instanceof \Illuminate\Database\Connection
                    || $connection->getDriverName() === 'pgsql') {
                    return ['status' => 'unsupported_connection'];
                }
                continue;
            }
            if ($connection->getEventDispatcher() !== $dispatcher) {
                return ['status' => 'dispatcher_mismatch'];
            }
            ++$result['pg_connections'];
            foreach (['write' => $connection->getRawPdo(), 'read' => $connection->getRawReadPdo()] as $role => $pdo) {
                $state = match (true) {
                    $pdo instanceof PDO => 'initialized', $pdo instanceof Closure => 'lazy',
                    $pdo === null => 'null', default => null,
                };
                if ($state === null) {
                    return ['status' => 'unsupported_handle'];
                }
                ++$result[$role . '_' . $state];
            }
            if ($connection->transactionLevel() > 0) {
                ++$result['transactions_open'];
            }
        }
        return $result;
    }

    /** At most 512 generations/run across a shared filesystem; failed admission is cached. */
    private static function admit(): bool
    {
        if (self::$worker['admitted']) {
            return self::$worker['directory'] !== null;
        }
        self::$worker['admitted'] = true;
        $root = storage_path('logs/task-sql-diagnostics');
        $directory = $root . '/' . self::$worker['run'];
        foreach ([$root, $directory] as $path) {
            // Common parents may have been created by another worker between is_dir and mkdir.
            if (!is_dir($path) && !is_link($path)) {
                @mkdir($path, 0700);
            }
            if (!is_dir($path) || is_link($path)) {
                return false;
            }
        }
        // Resolve ancestors as well: never follow a symlinked logs/storage directory.
        if (realpath($directory) !== $directory) {
            return false;
        }
        for ($slot = 0; $slot < 512; ++$slot) {
            $path = $directory . '/' . $slot;
            if (@mkdir($path, 0700)) {
                self::$worker['directory'] = $path;
                return true;
            }
        }
        return false;
    }

    /** One <=16KiB cumulative file/generation; replace atomically after every completed sample. */
    private static function write(): void
    {
        $directory = self::$worker['directory'];
        if ($directory === null || is_link($directory) || realpath($directory) !== $directory) {
            return;
        }
        $json = json_encode(['schema_version' => 1, 'run_id' => self::$worker['run'],
            'pid' => self::$worker['pid'], 'generation' => self::$worker['generation'],
            'samples' => self::$worker['records']], JSON_THROW_ON_ERROR);
        if (strlen($json) > 16384) {
            return;
        }
        $temporary = $directory . '/snapshot.tmp';
        $handle = @fopen($temporary, 'x');
        if ($handle === false) {
            return;
        }
        try {
            @chmod($temporary, 0600);
            $ok = fwrite($handle, $json) === strlen($json) && fflush($handle);
        } finally {
            fclose($handle);
        }
        if ($ok && !is_link($directory) && realpath($directory) === $directory) {
            @rename($temporary, $directory . '/snapshot.json');
        }
        @unlink($temporary);
    }
}
