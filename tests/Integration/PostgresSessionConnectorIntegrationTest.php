<?php

namespace Mallto\Tool\Tests\Integration;

use Closure;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Mallto\Tool\Domain\Database\PostgresSessionConnector;
use Mallto\Tool\Providers\ToolServiceProvider;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PostgresSessionConnectorIntegrationTest extends TestCase
{
    private function configuration(bool $persistent = false): array
    {
        $this->assertSame('1', getenv('PGSQL_SESSION_PRIVATE_FIXTURE'), 'Private isolated fixture is mandatory.');
        $this->assertSame('session_init_test', getenv('PGDATABASE'));
        return [
            'driver' => 'pgsql', 'host' => 'postgres', 'port' => 5432,
            'database' => 'session_init_test', 'username' => 'postgres',
            'password' => 'isolated-session-fixture', 'charset' => 'utf8',
            'timezone' => 'Asia/Shanghai', 'search_path' => 'public',
            'lock_timeout' => '8s', 'statement_timeout' => '30s',
            'options' => [PDO::ATTR_PERSISTENT => $persistent],
        ];
    }

    private function connector(): PostgresSessionConnector
    {
        return new PostgresSessionConnector([], new NullLogger());
    }

    private function settings(PDO $pdo): array
    {
        return $pdo->query("SELECT current_setting('lock_timeout') AS lock_timeout,
            current_setting('statement_timeout') AS statement_timeout,
            current_setting('TimeZone') AS timezone,
            current_setting('search_path') AS search_path")->fetch(PDO::FETCH_ASSOC);
    }

    private function assertSettings(PDO $pdo): void
    {
        $this->assertSame([
            'lock_timeout' => '8s', 'statement_timeout' => '30s',
            'timezone' => 'Asia/Shanghai', 'search_path' => 'public',
        ], $this->settings($pdo));
    }

    private function manager(): DatabaseManager
    {
        $app = new Application('/tmp');
        $app->instance('config', new Repository(['database' => [
            'default' => 'pgsql', 'connections' => ['pgsql' => $this->configuration()],
        ]]));
        $app->instance('events', new Dispatcher($app));
        $app->instance('log', new NullLogger());
        (new \ReflectionMethod(ToolServiceProvider::class, 'registerPostgresConnector'))
            ->invoke(new ToolServiceProvider($app));
        return new DatabaseManager($app, new ConnectionFactory($app));
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_first_connection_and_transaction_local_override(): void
    {
        $pdo = $this->connector()->connect($this->configuration());
        $this->assertSettings($pdo);
        $pdo->beginTransaction();
        $pdo->exec("SET LOCAL statement_timeout = '77ms'");
        $this->assertSame('77ms', $this->settings($pdo)['statement_timeout']);
        $pdo->rollBack();
        $this->assertSettings($pdo);
    }

    public function test_zero_timeout_is_valid(): void
    {
        $config = $this->configuration();
        $config['lock_timeout'] = 0;
        $config['statement_timeout'] = '0';
        $pdo = $this->connector()->connect($config);
        $this->assertSame('0', $this->settings($pdo)['lock_timeout']);
        $this->assertSame('0', $this->settings($pdo)['statement_timeout']);
    }

    public function test_explicit_reconnect_and_disconnect_query_reinitialize(): void
    {
        $db = $this->manager();
        $connection = $db->connection();
        $this->assertInstanceOf(Closure::class, $connection->getRawPdo());
        $pid = (int) $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid;
        $this->assertSettings($connection->getPdo());
        $db->reconnect();
        $this->assertInstanceOf(Closure::class, $connection->getRawPdo());
        $this->assertNotSame($pid, (int) $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid);
        $this->assertSettings($connection->getPdo());
        $db->disconnect();
        $this->assertSame(1, (int) $connection->selectOne('SELECT 1 AS value')->value);
        $this->assertSettings($connection->getPdo());
        $db->disconnect();
    }

    public function test_persistent_backend_reuse_restores_session_configuration(): void
    {
        $config = $this->configuration(true);
        $pdo = $this->connector()->connect($config);
        $pid = (int) $pdo->query('SELECT pg_backend_pid()')->fetchColumn();
        $pdo->exec("SET lock_timeout='123ms'; SET statement_timeout='456ms'; SET TIME ZONE 'UTC'; SET search_path TO pg_catalog");
        unset($pdo);
        gc_collect_cycles();
        $pdo = $this->connector()->connect($config);
        $this->assertSame($pid, (int) $pdo->query('SELECT pg_backend_pid()')->fetchColumn(), 'Must exercise actual persistent reuse.');
        $this->assertSettings($pdo);
    }

    public function test_automatic_reconnect_after_own_fixture_backend_terminates(): void
    {
        $db = $this->manager();
        $connection = $db->connection();
        $pid = (int) $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid;
        $control = $this->connector()->connect($this->configuration());
        $terminate = $control->prepare('SELECT pg_terminate_backend(?)');
        $terminate->execute([$pid]); // Only this test's own backend in its private fixture.
        $this->assertSame(1, (int) $connection->selectOne('SELECT 1 AS value')->value);
        $this->assertNotSame($pid, (int) $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid);
        $this->assertSettings($connection->getPdo());
        $db->disconnect();
    }

    public function test_separate_read_and_write_sessions_are_initialized(): void
    {
        $app = new Application('/tmp');
        $config = $this->configuration();
        $config['read'] = ['host' => 'postgres'];
        $config['write'] = ['host' => 'postgres'];
        $app->instance('config', new Repository(['database' => [
            'default' => 'pgsql', 'connections' => ['pgsql' => $config],
        ]]));
        $app->instance('events', new Dispatcher($app));
        $app->instance('log', new NullLogger());
        (new \ReflectionMethod(ToolServiceProvider::class, 'registerPostgresConnector'))
            ->invoke(new ToolServiceProvider($app));
        $db = new DatabaseManager($app, new ConnectionFactory($app));
        $connection = $db->connection();
        $read = $connection->getReadPdo();
        $write = $connection->getPdo();
        $this->assertNotSame($read, $write);
        $this->assertSettings($read);
        $this->assertSettings($write);
        $db->disconnect();
    }
}
