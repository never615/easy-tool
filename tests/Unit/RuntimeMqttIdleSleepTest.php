<?php

namespace Mallto\Tool\Tests\Unit;

use Illuminate\Foundation\Application;
use Mallto\Tool\Domain\Mqtt\RuntimeClientIdConnectionManager;
use Mallto\Tool\Domain\Mqtt\RuntimePrefixedMqttClient;
use PhpMqtt\Client\Contracts\Repository;
use PhpMqtt\Client\Exceptions\ConfigurationInvalidException;
use PhpMqtt\Client\Exceptions\DataTransferException;
use PhpMqtt\Client\Repositories\MemoryRepository;
use PhpMqtt\Client\Subscription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class RuntimeMqttIdleSleepTest extends TestCase
{
    protected function setUp(): void
    {
        require dirname(__DIR__) . '/fixtures/mqtt_sleep_spy.php';
        $GLOBALS['mqtt_idle_sleeps'] = [];
    }

    public function test_default_and_configured_sleep_leave_maintenance_in_parent_loop(): void
    {
        $default = new IdleSleepClientHarness('127.0.0.1');
        $default->loopOnce(microtime(true), true);
        $client = $this->client();
        $client->loop(true, true);
        $this->assertSame([100000, 20000], $GLOBALS['mqtt_idle_sleeps']);
        $this->assertSame(['resend', 'timeout', 'ping'], $client->maintenance);
    }

    public function test_explicit_positional_and_named_sleep_arguments_are_preserved(): void
    {
        $client = $this->client();
        $client->loopOnce(microtime(true), true, 100000);
        $client->loopOnce(loopStartedAt: microtime(true), allowSleep: true, sleepMicroseconds: 0);
        $client->loopOnce(loopStartedAt: microtime(true), allowSleep: true);
        $client->loopOnce(microtime(true));
        $client->loopOnce(microtime(true), false, 0);
        $this->assertSame([100000, 0, 20000], $GLOBALS['mqtt_idle_sleeps']);
    }

    public function test_parent_decodes_buffered_publish_without_sleeping(): void
    {
        $repository = new MemoryRepository();
        $received = [];
        $repository->addSubscription(new Subscription('test', 0, function ($topic, $payload) use (&$received): void {
            $received[] = [$topic, $payload];
        }));
        $client = $this->client($repository);
        $client->input = "\x30\x09\x00\x04testabc";
        $client->loopOnce(microtime(true), true);
        $this->assertSame([['test', 'abc']], $received);
        $this->assertSame([], $GLOBALS['mqtt_idle_sleeps']);
        $this->assertSame(['resend', 'timeout', 'ping'], $client->maintenance);
    }

    public function test_interrupt_is_reset_by_parent_so_the_next_loop_can_run(): void
    {
        $client = $this->client();
        $client->registerLoopEventHandler(function ($mqtt): void { $mqtt->interrupt(); });
        $client->loop(true);
        $client->loop(true);
        $this->assertSame([20000, 20000], $GLOBALS['mqtt_idle_sleeps']);
    }

    public function test_socket_failure_is_not_swallowed(): void
    {
        $client = $this->client();
        $client->failRead = true;
        $this->expectException(DataTransferException::class);
        $client->loopOnce(microtime(true), true);
    }

    #[DataProvider('validConnections')]
    public function test_real_manager_connects_and_uses_named_connection_sleep(string $name, array $extra, int $expected): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('Local TCP manager test requires pcntl.');
        }
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertIsResource($server, $error);
        $port = (int)substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            $socket = stream_socket_accept($server, 3);
            if ($socket === false) { exit(2); }
            stream_set_timeout($socket, 3);
            // Consume a complete CONNECT before replying with MQTT 3.1.1 CONNACK.
            $header = fread($socket, 1);
            $length = 0;
            $multiplier = 1;
            do {
                $byte = fread($socket, 1);
                if ($byte === '') { exit(3); }
                $digit = ord($byte);
                $length += ($digit & 127) * $multiplier;
                $multiplier *= 128;
            } while ($digit & 128);
            $body = '';
            while (strlen($body) < $length) {
                $chunk = fread($socket, $length - strlen($body));
                if ($chunk === '') { exit(4); }
                $body .= $chunk;
            }
            if ($header !== "\x10") { exit(5); }
            fwrite($socket, "\x20\x02\x00\x00");
            while (!feof($socket)) {
                if (fread($socket, 1024) === '') { break; }
            }
            fclose($socket);
            fclose($server);
            exit(0);
        }
        $manager = null;
        try {
            $app = new Application();
            $app->bind(Repository::class, MemoryRepository::class);
            $manager = new RuntimeClientIdConnectionManager($app, [
                'connections' => [$name => array_merge([
                    'host' => '127.0.0.1', 'port' => $port,
                    'protocol' => RuntimePrefixedMqttClient::MQTT_3_1_1,
                    'enable_logging' => false, 'connection_settings' => ['connect_timeout' => 2],
                ], $extra)],
            ]);
            $client = $manager->connection($name);
            $this->assertInstanceOf(RuntimePrefixedMqttClient::class, $client);
            $this->assertSame($client, $manager->connection($name));
            $client->loopOnce(microtime(true), true);
            $this->assertSame([$expected], $GLOBALS['mqtt_idle_sleeps']);
        } finally {
            if ($manager !== null) { $manager->disconnect($name); }
            fclose($server);
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
    }

    public static function validConnections(): array
    {
        return [
            ['lance_location_data', ['idle_sleep_ms' => '20'], 20000],
            ['lance_location_data', [], 100000],
            ['lance_diy_data', [], 100000],
            ['lance_location_data', ['idle_sleep_ms' => 5], 5000],
            ['lance_location_data', ['idle_sleep_ms' => 100], 100000],
        ];
    }

    #[DataProvider('invalidSleeps')]
    public function test_invalid_configuration_fails_before_opening_a_socket($value): void
    {
        $manager = new RuntimeClientIdConnectionManager(new Application(), [
            'connections' => ['lance_location_data' => ['host' => '127.0.0.1', 'idle_sleep_ms' => $value]],
        ]);
        $this->expectException(ConfigurationInvalidException::class);
        $manager->connection('lance_location_data');
    }

    public static function invalidSleeps(): array
    {
        return array_map(static fn ($value) => [$value], [0, -1, 4, 101, 'no', '20.5', 20.0, null, true, false, [], '999999999999999999999999']);
    }

    private function client(?Repository $repository = null): IdleSleepClientHarness
    {
        return new IdleSleepClientHarness('127.0.0.1', repository: $repository, idleSleepMicroseconds: 20000);
    }
}

class IdleSleepClientHarness extends RuntimePrefixedMqttClient
{
    public array $maintenance = [];
    public string $input = '';
    public bool $failRead = false;

    protected function readAllAvailableDataFromSocket(bool $withAutoReconnectIfConfigured = false): string
    {
        if ($this->failRead) { throw new DataTransferException(DataTransferException::EXCEPTION_RX_DATA, 'socket probe failed'); }
        $input = $this->input;
        $this->input = '';
        return $input;
    }

    protected function resendPendingMessages(): void { $this->maintenance[] = 'resend'; }
    protected function handlePingTimeout(): void { $this->maintenance[] = 'timeout'; }
    protected function nextPingAt(): float { return 0; }
    protected function ping(): void { $this->maintenance[] = 'ping'; }
}
