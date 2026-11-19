<?php

namespace Mallto\Tool\Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Mallto\Tool\Domain\SwooleTaskMonitor\MonitoredSwooleTask;
use Mallto\Tool\Domain\SwooleTaskMonitor\SwooleTaskSqlDiagnostics as Probe;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

final class SwooleTaskSqlDiagnosticsTest extends TestCase
{
    private const RUN = '92222222-2222-4222-8222-222222222222';
    private Application $app;
    private string $storage;
    private ?Container $previous;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $this->app = new Application('/tmp');
        Container::setInstance($this->app);
        $this->storage = sys_get_temp_dir() . '/task-sql-test-' . bin2hex(random_bytes(8));
        mkdir($this->storage, 0700);
        mkdir($this->storage . '/logs', 0700);
        $this->app->useStoragePath($this->storage);
        $this->app->instance('config', new Repository(['app' => ['env' => 'testing'],
            'swoole_task_monitor' => ['enabled' => false, 'sql_diagnostics' => [
                'enabled' => true, 'run_id' => self::RUN, 'expires_at' => time() + 120,
                'sample_every' => 1, 'max_samples' => 20]] ]));
        foreach (['current', 'worker', 'dispatchers', 'instances'] as $name) {
            (new ReflectionProperty(Probe::class, $name))->setValue(null, null);
        }
        foreach (['db', 'events', 'redis'] as $name) {
            $this->app->bind($name, static function (): never { throw new RuntimeException('Service resolved'); });
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->storage);
        Container::setInstance($this->previous);
    }

    private function worker(): ?array
    {
        return (new ReflectionProperty(Probe::class, 'worker'))->getValue();
    }

    private function changeWorker(array $values): void
    {
        (new ReflectionProperty(Probe::class, 'worker'))->setValue(null, array_replace($this->worker(), $values));
    }

    private function sample(): array
    {
        $files = glob($this->storage . '/logs/task-sql-diagnostics/' . self::RUN . '/*/snapshot.json');
        $this->assertCount(1, $files);
        $json = file_get_contents($files[0]);
        $this->assertLessThanOrEqual(16384, strlen($json));
        foreach (['private-sql', 'private-binding', 'private-exception', 'SqlProbeTask', 'private-connection'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR)['samples'];
    }

    private function database(array $connections = []): array
    {
        $events = new Dispatcher($this->app);
        $manager = new DatabaseManager($this->app, new ConnectionFactory($this->app));
        $this->app->instance('db', $manager);
        $this->app->instance('events', $events);
        if ($connections === []) {
            // Exercise the real Laravel PG query/event path with an in-memory PDO; no remote DB is touched.
            $connections = [new PostgresConnection(new PDO('sqlite::memory:'), 'synthetic_test', '', ['driver' => 'pgsql'])];
        }
        foreach ($connections as $connection) {
            $connection->setEventDispatcher($events);
        }
        (new ReflectionProperty(DatabaseManager::class, 'connections'))->setValue($manager, $connections);
        return [$connections[0], $events, $manager];
    }

    public function test_default_configuration_and_all_invalid_activation_are_inert(): void
    {
        $defaults = require __DIR__ . '/../../config/swoole_task_monitor.php';
        $this->assertFalse($defaults['sql_diagnostics']['enabled']);
        $valid = config('swoole_task_monitor.sql_diagnostics');
        foreach ([['enabled' => false], ['run_id' => '../secret'], ['expires_at' => time() - 1],
            ['expires_at' => time() + 1000], ['expires_at' => '123'], ['run_id' => '']] as $overrides) {
            config()->set('swoole_task_monitor.sql_diagnostics', array_replace($valid, $overrides));
            $this->assertNull(Probe::begin('private-class'));
            $this->assertNull($this->worker());
        }
        config()->set('swoole_task_monitor.sql_diagnostics', $valid);
        config()->set('app.env', 'production');
        $this->assertNull(Probe::begin('private-class'));
        $this->assertNull($this->worker());
        $this->assertDirectoryDoesNotExist($this->storage . '/logs/task-sql-diagnostics');
    }

    public function test_completed_laravel_queries_scope_filters_and_listener_are_stable(): void
    {
        [$pg, $events] = $this->database();
        $existing = 0;
        $events->listen(QueryExecuted::class, static function () use (&$existing): void { ++$existing; });
        for ($i = 0; $i < 2; ++$i) {
            $scope = Probe::begin('private-class');
            $pg->select('select ? as value /* private-sql */', ['private-binding']);
            $pg->pretend(fn () => $pg->select('select 1'));
            $sqlite = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:');
            $sqlite->setEventDispatcher($events);
            $sqlite->select('select 1');
            $scope->finish(false);
            $pg->select('select 1'); // outside Task scope
        }
        $samples = $this->sample();
        $this->assertCount(2, $samples);
        foreach ($samples as $sample) {
            $this->assertTrue($sample['complete']);
            $this->assertSame(1, $sample['pg_successful_laravel_queries']);
            $this->assertGreaterThanOrEqual(0, $sample['pg_successful_laravel_query_ms']);
            $this->assertSame(1, $sample['before']['write_initialized']);
            $this->assertSame(1, $sample['before']['read_null']);
        }
        $this->assertCount(2, $events->getRawListeners()[QueryExecuted::class]);
        $this->assertSame(8, $existing);
    }

    public function test_lazy_handles_and_transactions_are_read_without_connecting(): void
    {
        $opens = 0;
        $pg = new PostgresConnection(function () use (&$opens): never { ++$opens; throw new RuntimeException('Unexpected connect'); },
            'synthetic_test', '', ['driver' => 'pgsql']);
        $pg->setReadPdo(static fn () => throw new RuntimeException('Unexpected read connect'));
        (new ReflectionProperty($pg, 'transactions'))->setValue($pg, 2);
        $this->database([$pg]);
        Probe::begin('private-class')->finish(false);
        $sample = $this->sample()[0];
        $this->assertSame(1, $sample['before']['write_lazy']);
        $this->assertSame(1, $sample['before']['read_lazy']);
        $this->assertSame(1, $sample['before']['transactions_open']);
        $this->assertSame(0, $opens);
        $this->assertTrue($sample['complete']);
        $this->assertSame(0, $sample['pg_successful_laravel_queries']);
    }

    public function test_missing_manager_and_dispatcher_mismatch_are_unknown_not_zero(): void
    {
        $events = new Dispatcher($this->app);
        $this->app->instance('events', $events);
        Probe::begin('private-class')->finish(false);
        [$pg] = $this->database();
        $pg->setEventDispatcher(new Dispatcher($this->app));
        Probe::begin('private-class')->finish(false);
        $samples = $this->sample();
        $this->assertSame('manager_not_instantiated', $samples[0]['before']['status']);
        $this->assertSame('dispatcher_mismatch', $samples[1]['before']['status']);
        foreach ($samples as $sample) {
            $this->assertFalse($sample['complete']);
            $this->assertNull($sample['pg_successful_laravel_queries']);
        }
    }

    public function test_transient_custom_postgres_event_is_unknown_even_when_absent_from_manager(): void
    {
        [, $events] = $this->database();
        $custom = new class(new PDO('sqlite::memory:'), 'synthetic_test', '', ['driver' => 'pgsql']) extends PostgresConnection {};
        $custom->setEventDispatcher($events);
        $scope = Probe::begin('private-class');
        $custom->select('select 1');
        $scope->finish(false);
        $sample = $this->sample()[0];
        $this->assertFalse($sample['complete']);
        $this->assertNull($sample['pg_successful_laravel_queries']);
    }

    public function test_forgetting_manager_and_connection_overflow_invalidate_coverage(): void
    {
        [, , $manager] = $this->database();
        $scope = Probe::begin('private-class');
        $this->app->forgetInstance('db');
        $scope->finish(false);
        $this->app->instance('db', $manager);
        $connection = $manager->getConnections()[0];
        (new ReflectionProperty($manager, 'connections'))->setValue($manager, array_fill(0, 17, $connection));
        Probe::begin('private-class')->finish(false);
        $samples = $this->sample();
        $this->assertSame('manager_not_instantiated', $samples[0]['after']['status']);
        $this->assertSame('connection_overflow', $samples[1]['before']['status']);
        $this->assertNull($samples[1]['pg_successful_laravel_queries']);
    }

    public function test_stride_cap_and_last_sample_are_bounded_without_resolving_services(): void
    {
        config()->set('swoole_task_monitor.sql_diagnostics.sample_every', 100);
        $this->database();
        Probe::begin('private-class')->finish(false);
        $this->app->forgetInstance('events');
        $this->app->forgetInstance('db');
        for ($i = 2; $i <= 100; ++$i) {
            Probe::begin('private-class')->finish(false);
        }
        $this->assertSame(1, $this->worker()['samples']);
        $this->assertCount(1, $this->sample());
        $this->database();
        Probe::begin('private-class')->finish(false);
        $this->assertCount(2, $this->sample());
        config()->set('swoole_task_monitor.sql_diagnostics.sample_every', 1);
        config()->set('swoole_task_monitor.sql_diagnostics.max_samples', 1000);
        for ($i = 0; $i < 30; ++$i) {
            Probe::begin('private-class')->finish(false);
        }
        $this->assertCount(20, $this->sample());
        $this->assertSame(20, $this->worker()['samples']);
        $this->app->forgetInstance('events');
        $this->app->forgetInstance('db');
        Probe::begin('private-class')->finish(false);
    }

    public function test_both_sampled_and_unsampled_nested_tasks_cannot_be_attributed(): void
    {
        [$pg] = $this->database();
        config()->set('swoole_task_monitor.sql_diagnostics.sample_every', 2);
        $outer = Probe::begin('private-class'); // sampled call 1
        $inner = Probe::begin('private-class');
        $pg->select('select 1');
        $inner->finish(false);
        $outer->finish(false);
        $outer = Probe::begin('private-class'); // skipped call 2
        $inner = Probe::begin('private-class'); // must not count as eligible call 3
        $pg->select('select 1');
        $inner->finish(false);
        $outer->finish(false);
        Probe::begin('private-class')->finish(false); // sampled call 3
        $samples = $this->sample();
        $this->assertCount(2, $samples);
        $this->assertFalse($samples[0]['complete']);
        $this->assertNull($samples[0]['pg_successful_laravel_queries']);
        $this->assertTrue($samples[1]['complete']);
        $this->assertSame(3, $samples[1]['eligible_call']);
    }

    public function test_monotonic_deadline_cannot_be_extended_and_crossing_it_is_incomplete(): void
    {
        [$pg] = $this->database();
        Probe::begin('private-class')->finish(false);
        $deadline = $this->worker()['deadline_ns'];
        config()->set('swoole_task_monitor.sql_diagnostics.expires_at', time() + 119);
        $scope = Probe::begin('private-class');
        $this->assertSame($deadline, $this->worker()['deadline_ns']);
        $this->changeWorker(['deadline_ns' => hrtime(true) - 1]);
        $pg->select('select 1');
        $scope->finish(false);
        $this->assertNull(Probe::begin('private-class'));
        $this->assertLessThan($deadline, $this->worker()['deadline_ns']);
        $this->assertFalse($this->sample()[1]['complete']);
        $this->assertNull($this->sample()[1]['pg_successful_laravel_queries']);
    }

    public function test_listener_configuration_failure_never_turns_a_successful_query_into_failure(): void
    {
        [$pg] = $this->database();
        $scope = Probe::begin('private-class');
        $previous = config();
        $this->app->instance('config', new class extends Repository {
            public function get($key, $default = null): mixed { throw new RuntimeException('private-exception'); }
        });
        try {
            $this->assertCount(1, $pg->select('select 1'));
        } finally {
            $this->app->instance('config', $previous);
        }
        $scope->finish(false);
        $this->assertFalse($this->sample()[0]['complete']);
        Probe::begin('private-class')->finish(false);
        $this->assertTrue($this->sample()[1]['complete']);
    }

    public function test_concurrent_workers_share_parent_directories_but_claim_distinct_slots(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required for concurrent admission');
        }
        $this->database();
        [$read, $write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $children = [];
        for ($i = 0; $i < 8; ++$i) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                fclose($write);
                fread($read, 1);
                Probe::begin('private-class')->finish(false);
                exit(0);
            }
            $this->assertGreaterThan(0, $pid);
            $children[] = $pid;
        }
        fclose($read);
        fwrite($write, str_repeat('x', 8));
        fclose($write);
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        $this->assertCount(8, glob($this->storage . '/logs/task-sql-diagnostics/' . self::RUN . '/*/snapshot.json'));
    }

    public function test_invalid_event_and_writer_failure_preserve_original_business_exception(): void
    {
        [$pg, $events] = $this->database();
        $exception = new RuntimeException('private-exception');
        $task = new SqlProbeTask(function () use ($pg, $events, $exception): never {
            $events->dispatch(new QueryExecuted('private-sql', ['private-binding'], -1, $pg));
            throw $exception;
        });
        try {
            $task->handle();
            $this->fail('Business exception was swallowed');
        } catch (RuntimeException $actual) {
            $this->assertSame($exception, $actual);
        }
        $this->assertFalse($this->sample()[0]['complete']);
        $this->assertTrue($this->sample()[0]['business_threw']);
        $this->assertNull((new ReflectionProperty(Probe::class, 'current'))->getValue());
        // Exclusive temporary file admission fails. Business result and next scope still survive.
        file_put_contents($this->worker()['directory'] . '/snapshot.tmp', 'occupied');
        $ok = new SqlProbeTask(fn () => $pg->select('select 1'));
        $ok->handle();
        $this->assertNull((new ReflectionProperty(Probe::class, 'current'))->getValue());
        unlink($this->worker()['directory'] . '/snapshot.tmp');
        $ok->handle();
        $samples = $this->sample();
        $this->assertCount(3, $samples);
        $this->assertTrue($samples[2]['complete']);
    }

    public function test_disabled_monitored_lifecycle_creates_no_services_or_files(): void
    {
        config()->set('swoole_task_monitor.sql_diagnostics.enabled', false);
        $calls = 0;
        (new SqlProbeTask(function () use (&$calls): void { ++$calls; }))->handle();
        $this->assertSame(1, $calls);
        $this->assertNull($this->worker());
        $this->assertDirectoryDoesNotExist($this->storage . '/logs/task-sql-diagnostics');
    }

    public function test_symlink_and_full_generation_admission_are_inert_and_cached(): void
    {
        $this->database();
        symlink($this->storage . '/logs', $this->storage . '/logs/task-sql-diagnostics');
        Probe::begin('private-class')->finish(false);
        $this->assertNull($this->worker()['directory']);
        $this->assertTrue($this->worker()['admitted']);
        unlink($this->storage . '/logs/task-sql-diagnostics');
        (new ReflectionProperty(Probe::class, 'worker'))->setValue(null, null);
        $directory = $this->storage . '/logs/task-sql-diagnostics/' . self::RUN;
        mkdir($directory, 0700, true);
        for ($i = 0; $i < 512; ++$i) {
            mkdir($directory . '/' . $i, 0700);
        }
        Probe::begin('private-class')->finish(false);
        $this->assertNull($this->worker()['directory']);
        rmdir($directory . '/0');
        Probe::begin('private-class')->finish(false);
        $this->assertNull($this->worker()['directory']); // no repeated scans after admission rejection
    }

    public function test_coroutine_entry_and_events_cannot_be_attributed(): void
    {
        if (!class_exists('Swoole\\Coroutine')) {
            $this->markTestSkipped('Swoole required for real coroutine boundary');
        }
        [$pg] = $this->database();
        $scope = Probe::begin('private-class');
        \Swoole\Coroutine\run(function () use ($pg): void {
            $pg->select('select 1');
        });
        $scope->finish(false);
        $this->assertFalse($this->sample()[0]['complete']);
        \Swoole\Coroutine\run(function (): void {
            $this->assertNull(Probe::begin('private-class'));
        });
        Probe::begin('private-class')->finish(false);
        $this->assertTrue($this->sample()[1]['complete']);
    }
}

final class SqlProbeTask extends MonitoredSwooleTask
{
    public function __construct(private readonly \Closure $body) {}
    protected function handleTask(): void { ($this->body)(); }
}
