<?php

namespace Mallto\Tool\Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Mallto\Tool\Providers\ToolServiceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TransactionGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_termination_does_not_resolve_an_unused_database_manager(): void
    {
        $app = $this->application();
        $app->singleton('db', function () {
            $this->fail('Termination must not resolve an unused database manager.');
        });

        $app->terminate();

        $this->assertFalse($app->resolved('db'));
    }

    public function test_termination_does_not_create_a_default_database_connection(): void
    {
        $app = $this->application();
        $manager = $this->databaseManager($app);
        $manager->extend('sqlite', function () {
            $this->fail('Termination must not create a database connection.');
        });

        $app->terminate();

        $this->assertSame([], $manager->getConnections());
    }

    public function test_termination_does_not_open_pdo_for_an_idle_existing_connection(): void
    {
        $app = $this->application();
        $manager = $this->databaseManager($app);
        $manager->extend('sqlite', function () {
            return new Connection(function () {
                $this->fail('An idle connection must not open PDO during termination.');
            });
        });
        $connection = $manager->connection();

        $app->terminate();

        $this->assertSame(0, $connection->transactionLevel());
    }

    public function test_termination_rolls_back_nested_transactions_on_existing_connections(): void
    {
        $app = $this->application();
        $manager = $this->databaseManager($app);
        foreach (['default', 'secondary'] as $name) {
            $connection = $manager->connection($name);
            $connection->statement('create table records (id integer)');
            $connection->beginTransaction();
            $connection->beginTransaction();
            $connection->insert('insert into records (id) values (1)');
        }

        $app->terminate();

        foreach ($manager->getConnections() as $connection) {
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertSame(0, (int) $connection->selectOne('select count(*) as count from records')->count);
        }
    }

    private function application(): Application
    {
        $app = new Application();
        $app->instance('log', new NullLogger());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $provider = new ToolServiceProvider($app);
        $method = new \ReflectionMethod($provider, 'registerTransactionGuard');
        $method->invoke($provider);

        return $app;
    }

    private function databaseManager(Application $app): DatabaseManager
    {
        $config = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
        $app->instance('config', new Repository(['database' => [
            'default' => 'default',
            'connections' => ['default' => $config, 'secondary' => $config],
        ]]));
        $app->singleton('db', fn () => new DatabaseManager($app, new ConnectionFactory($app)));

        return $app->make('db');
    }
}
