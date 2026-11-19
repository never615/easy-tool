<?php

namespace Mallto\Tool\Tests\Integration;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\PostgresConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Mallto\Tool\Domain\SwooleTaskMonitor\MonitoredSwooleTask;
use Mallto\Tool\Domain\SwooleTaskMonitor\SwooleTaskSqlDiagnostics as Probe;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Explicitly opted-in disposable PostgreSQL fixture; no application bootstrap or shared database. */
final class SwooleTaskSqlDiagnosticsPostgresTest extends TestCase
{
    public function test_actual_task_postgres_event_scope_and_disabled_restoration(): void
    {
        if (getenv('TASK_SQL_TEST_PG') !== '1') {
            $this->markTestSkipped('Requires isolated loopback PostgreSQL task_sql_test fixture');
        }
        $previous = Container::getInstance();
        $app = new Application('/tmp');
        Container::setInstance($app);
        $storage = sys_get_temp_dir() . '/task-sql-pg-' . bin2hex(random_bytes(8));
        mkdir($storage . '/logs', 0700, true);
        $app->useStoragePath($storage);
        $run = '93333333-3333-4333-8333-333333333333';
        $app->instance('config', new Repository(['app' => ['env' => 'testing'],
            'swoole_task_monitor' => ['enabled' => false, 'sql_diagnostics' => [
                'enabled' => true, 'run_id' => $run, 'expires_at' => time() + 120,
                'sample_every' => 1, 'max_samples' => 2]] ]));
        foreach (['current', 'worker', 'dispatchers', 'instances'] as $name) {
            (new ReflectionProperty(Probe::class, $name))->setValue(null, null);
        }
        try {
            $opens = 0;
            $pg = new PostgresConnection(function () use (&$opens): PDO {
                ++$opens;
                return new PDO('pgsql:host=127.0.0.1;dbname=task_sql_test', 'postgres', 'isolated-task-sql-only');
            }, 'task_sql_test', '', ['driver' => 'pgsql']);
            $events = new Dispatcher($app);
            $pg->setEventDispatcher($events);
            $manager = new DatabaseManager($app, new ConnectionFactory($app));
            (new ReflectionProperty($manager, 'connections'))->setValue($manager, ['private-connection' => $pg]);
            $app->instance('db', $manager);
            $app->instance('events', $events);
            $task = new class($pg) extends MonitoredSwooleTask {
                public function __construct(private PostgresConnection $pg) {}
                protected function handleTask(): void
                {
                    $rows = $this->pg->select('select ?::text as value /* private-sql */', ['private-binding']);
                    if ($rows[0]->value !== 'private-binding') {
                        throw new \RuntimeException('Unexpected fixture result');
                    }
                }
            };
            $task->handle();
            $files = glob($storage . '/logs/task-sql-diagnostics/' . $run . '/*/snapshot.json');
            $this->assertCount(1, $files);
            $content = file_get_contents($files[0]);
            $samples = json_decode($content, true, flags: JSON_THROW_ON_ERROR)['samples'];
            $this->assertTrue($samples[0]['complete']);
            $this->assertSame(1, $samples[0]['pg_successful_laravel_queries']);
            $this->assertSame(1, $samples[0]['before']['write_lazy']);
            $this->assertSame(1, $samples[0]['after']['write_initialized']);
            $this->assertSame(1, $opens); // only the business SELECT opened the fixture connection
            config()->set('swoole_task_monitor.sql_diagnostics.enabled', false);
            $task->handle();
            $this->assertSame($content, file_get_contents($files[0]));
            $this->assertFalse(config('swoole_task_monitor.sql_diagnostics.enabled'));
            $this->assertStringNotContainsString('private-sql', $content);
            $this->assertStringNotContainsString('private-binding', $content);
            $this->assertStringNotContainsString('private-connection', $content);
        } finally {
            (new Filesystem())->deleteDirectory($storage);
            Container::setInstance($previous);
            foreach (['current', 'worker', 'dispatchers', 'instances'] as $name) {
                (new ReflectionProperty(Probe::class, $name))->setValue(null, null);
            }
        }
    }
}
