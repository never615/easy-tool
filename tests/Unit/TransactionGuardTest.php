<?php

namespace Mallto\Tool\Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\QueueManager;
use Encore\Admin\Facades\Admin;
use Mallto\Tool\Exception\Handler;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\Facade;
use Mallto\Tool\Providers\ToolServiceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TransactionGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        \Illuminate\Queue\Queue::createPayloadUsing(null);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    #[DataProvider('entrypoints')]
    public function test_guard_does_not_resolve_an_unused_database_manager(string $entrypoint): void
    {
        $app = $this->application();
        $app->singleton('db', function () {
            $this->fail('Transaction cleanup must not resolve an unused database manager.');
        });

        $this->runGuard($app, $entrypoint);

        $this->assertFalse($app->resolved('db'));
    }

    #[DataProvider('entrypoints')]
    public function test_guard_does_not_create_a_default_database_connection(string $entrypoint): void
    {
        $app = $this->application();
        $manager = $this->databaseManager($app);
        $manager->extend('sqlite', function () {
            $this->fail('Transaction cleanup must not create a database connection.');
        });

        $this->runGuard($app, $entrypoint);

        $this->assertSame([], $manager->getConnections());
    }

    #[DataProvider('entrypoints')]
    public function test_guard_does_not_open_pdo_for_an_idle_existing_connection(string $entrypoint): void
    {
        $app = $this->application();
        $manager = $this->databaseManager($app);
        $manager->extend('sqlite', function () {
            return new Connection(function () {
                $this->fail('An idle connection must not open PDO during transaction cleanup.');
            });
        });
        $connection = $manager->connection();

        $this->runGuard($app, $entrypoint);

        $this->assertSame(0, $connection->transactionLevel());
    }

    #[DataProvider('entrypoints')]
    public function test_guard_rolls_back_nested_transactions_on_existing_connections(string $entrypoint): void
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

        $this->runGuard($app, $entrypoint);

        foreach ($manager->getConnections() as $connection) {
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertSame(0, (int) $connection->selectOne('select count(*) as count from records')->count);
        }
    }

    public static function entrypoints(): array
    {
        return [['termination'], ['queue'], ['handler']];
    }

    public function test_queue_and_handler_propagate_rollback_failure(): void
    {
        foreach (['queue', 'handler'] as $entrypoint) {
            $app = $this->application();
            $manager = $this->databaseManager($app);
            $failure = new \RuntimeException('rollback failed');
            $connection = $this->createMock(Connection::class);
            $connection->method('setReadWriteType')->willReturnSelf();
            $connection->method('transactionLevel')->willReturn(1);
            $connection->expects($this->once())->method('rollBack')->willThrowException($failure);
            $manager->extend('sqlite', fn () => $connection);
            $manager->connection();

            try {
                $this->runGuard($app, $entrypoint);
                $this->fail('Rollback failure must stop processing.');
            } catch (\RuntimeException $caught) {
                $this->assertSame($failure, $caught);
            }
        }
    }

    private function runGuard(Application $app, string $entrypoint): void
    {
        if ($entrypoint === 'termination') {
            $app->terminate();
        } elseif ($entrypoint === 'queue') {
            $app['events']->dispatch(new Looping('redis', 'default'));
        } else {
            Admin::swap(new class {
                public function user() { return null; }
            });
            $handler = new class($app) extends Handler {
                protected function interJsonHandler(\Throwable $exception, $request, $isAdmin = false)
                {
                    return new JsonResponse(['message' => $exception->getMessage()], 422);
                }
            };
            $response = $handler->render(Request::create('/test', 'GET', [], [], [], [
                'HTTP_ACCEPT' => 'application/json',
            ]), new \RuntimeException('original error'));
            $this->assertSame(422, $response->getStatusCode());
            $this->assertSame(['message' => 'original error'], $response->getData(true));
        }
    }

    private function application(): Application
    {
        $app = new Application();
        $app->instance('log', new NullLogger());
        $app->instance('config', new Repository(['queue' => [
            'default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']],
        ]]));
        $app->instance('events', new Dispatcher($app));
        $queue = new QueueManager($app);
        $queue->addConnector('sync', fn () => new \Illuminate\Queue\Connectors\SyncConnector());
        $app->instance('queue', $queue);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $provider = new ToolServiceProvider($app);
        $method = new \ReflectionMethod($provider, 'registerTransactionGuard');
        $method->invoke($provider);
        (new \ReflectionMethod($provider, 'queueBoot'))->invoke($provider);

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
