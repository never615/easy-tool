<?php

namespace Mallto\Tool\Tests\Unit;

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
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class PostgresSessionConnectorTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    private function manager(array $overrides = []): array
    {
        $app = new Application('/tmp');
        $config = array_replace([
            'driver' => 'pgsql', 'host' => 'unused.invalid', 'database' => 'synthetic_test',
            'timezone' => 'Asia/Shanghai', 'search_path' => 'public',
            'lock_timeout' => '8s', 'statement_timeout' => '30s',
        ], $overrides);
        $app->instance('config', new Repository(['database' => [
            'default' => 'pgsql', 'connections' => ['pgsql' => $config],
        ]]));
        $logger = new SessionTestLogger();
        $app->instance('log', $logger);
        $app->instance('events', new Dispatcher($app));
        (new \ReflectionMethod(ToolServiceProvider::class, 'registerPostgresConnector'))
            ->invoke(new ToolServiceProvider($app));
        $this->assertInstanceOf(PostgresSessionConnector::class, $app->make('db.connector.pgsql'));
        $state = (object) ['pdos' => [], 'openFailure' => null, 'timeoutFailure' => false];
        $app->bind('db.connector.pgsql', fn () => new class($state, $logger) extends PostgresSessionConnector {
            public function __construct(private object $state, SessionTestLogger $logger)
            {
                parent::__construct(['lock_timeout' => '8s', 'statement_timeout' => '30s'], $logger);
            }
            public function createConnection($dsn, array $config, array $options)
            {
                if ($this->state->openFailure !== null) {
                    throw $this->state->openFailure;
                }
                $pdo = new SessionTestPdo($this->state->timeoutFailure);
                $this->state->pdos[] = $pdo;
                return $pdo;
            }
        });
        return [new DatabaseManager($app, new ConnectionFactory($app)), $state, $logger, $app];
    }

    public function test_metadata_preserves_lazy_pdo(): void
    {
        [$manager, $state] = $this->manager();
        $connection = $manager->connection();
        $this->assertSame('pgsql', $connection->getDriverName());
        $connection->getName();
        $connection->getDatabaseName();
        $connection->getConfig();
        $this->assertSame(0, $connection->transactionLevel());
        $connection->query()->from('never_executed')->toSql();
        $this->assertSame([], $state->pdos);
        $this->assertInstanceOf(Closure::class, $connection->getRawPdo());
    }

    public function test_first_connect_preserves_parent_settings_and_initializes_once(): void
    {
        [$manager, $state] = $this->manager(['isolation_level' => 'read committed', 'synchronous_commit' => 'on']);
        $connection = $manager->connection();
        $pdo = $connection->getPdo();
        $this->assertCount(1, $state->pdos);
        $this->assertSame([
            ['set session characteristics as transaction isolation level read committed', null],
            ["set time zone 'Asia/Shanghai'", null],
            ['set search_path to "public"', null],
            ["set synchronous_commit to 'on'", null],
            ['select set_config(?, ?, false)', ['lock_timeout', '8s']],
            ['select set_config(?, ?, false)', ['statement_timeout', '30s']],
        ], $pdo->executed);
        $this->assertSame($pdo, $connection->getPdo());
        $this->assertCount(6, $pdo->executed);
    }

    public function test_reconnect_is_lazy_and_reinitializes_the_new_pdo(): void
    {
        [$manager, $state] = $this->manager();
        $connection = $manager->connection();
        $first = $connection->getPdo();
        $manager->reconnect();
        $this->assertCount(1, $state->pdos);
        $this->assertInstanceOf(Closure::class, $connection->getRawPdo());
        $second = $connection->getPdo();
        $this->assertNotSame($first, $second);
        $this->assertSame($first->executed, $second->executed);
    }

    public function test_read_and_write_pdos_are_both_initialized(): void
    {
        [$manager, $state] = $this->manager(['read' => ['host' => 'read.invalid'], 'write' => ['host' => 'write.invalid']]);
        $connection = $manager->connection();
        $this->assertSame([], $state->pdos);
        $connection->getReadPdo();
        $connection->getPdo();
        $this->assertCount(2, $state->pdos);
        $this->assertSame($state->pdos[0]->executed, $state->pdos[1]->executed);
    }

    public function test_zero_values_are_preserved_and_values_are_bound(): void
    {
        [$manager, $state] = $this->manager(['lock_timeout' => 0, 'statement_timeout' => '0']);
        $manager->connection()->getPdo();
        $this->assertSame([
            ['select set_config(?, ?, false)', ['lock_timeout', '0']],
            ['select set_config(?, ?, false)', ['statement_timeout', '0']],
        ], array_slice($state->pdos[0]->executed, -2));
    }

    public function test_defaults_apply_when_alias_has_no_timeout_options(): void
    {
        [$manager, $state, $logger, $app] = $this->manager();
        $config = $manager->connection()->getConfig();
        unset($config['lock_timeout'], $config['statement_timeout']);
        $app['config']->set('database.connections.alias', $config);
        $manager->connection('alias')->getPdo();
        $this->assertSame([
            ['select set_config(?, ?, false)', ['lock_timeout', '8s']],
            ['select set_config(?, ?, false)', ['statement_timeout', '30s']],
        ], array_slice($state->pdos[0]->executed, -2));
    }

    public function test_timeout_failure_is_best_effort_and_does_not_log_driver_text(): void
    {
        [$manager, $state, $logger] = $this->manager();
        $state->timeoutFailure = true;
        $this->assertInstanceOf(PDO::class, $manager->connection()->getPdo());
        $this->assertCount(1, $logger->records);
        $this->assertSame(['exception_class' => \RuntimeException::class], $logger->records[0]['context']);
        $this->assertStringNotContainsString('sensitive-driver-text', json_encode($logger->records));
    }

    public function test_open_failure_is_not_swallowed(): void
    {
        [$manager, $state, $logger] = $this->manager();
        $failure = new \RuntimeException('open failed');
        $state->openFailure = $failure;
        try {
            $manager->connection()->getPdo();
            $this->fail('Opening must fail.');
        } catch (\RuntimeException $caught) {
            $this->assertSame($failure, $caught);
        }
        $this->assertSame([], $logger->records);
    }
}

final class SessionTestPdo extends PDO
{
    public array $executed = [];
    public function __construct(private bool $timeoutFailure = false) {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new class($this, $query, $this->timeoutFailure) extends PDOStatement {
            public function __construct(private SessionTestPdo $pdo, private string $sql, private bool $failure) {}
            public function execute(?array $params = null): bool
            {
                if ($this->failure && str_contains($this->sql, 'set_config')) {
                    throw new \RuntimeException('sensitive-driver-text');
                }
                $this->pdo->executed[] = [$this->sql, $params];
                return true;
            }
        };
    }
}

final class SessionTestLogger extends AbstractLogger
{
    public array $records = [];
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = compact('level', 'message', 'context');
    }
}
