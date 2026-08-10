<?php

namespace Mallto\Tool\Processes;

use Hhxsv5\LaravelS\Swoole\Process\CustomProcessInterface;
use Illuminate\Support\Facades\Log;
use Mallto\Tool\Domain\RuntimeConfig\RuntimeConfigSwooleStore;
use Swoole\Http\Server;
use Swoole\Process;
use Throwable;

final class RuntimeConfigInvalidationSubscriberProcess implements CustomProcessInterface
{
    private const MAX_IDENTITIES = 256;

    public static function callback(Server $swoole, Process $process)
    {
        $store = app(RuntimeConfigSwooleStore::class);
        $channel = (string) config('runtime_config.channel', 'runtime_config:invalidate');

        $redis = null;
        try {
            $redis = self::createRedisConnection();
            $redis->subscribe([$channel], static function (\Redis $redis, string $channel, string $message) use ($store): void {
                self::handleMessage($store, $message);
            });
        } catch (Throwable $exception) {
            Log::warning('[RuntimeConfigInvalidationSubscriber] subscription stopped', [
                'error_type' => $exception::class,
            ]);
        } finally {
            if ($redis instanceof \Redis) {
                try {
                    $redis->close();
                } catch (Throwable) {
                    // The process is already stopping or reconnecting after a broken socket.
                }
            }
        }
    }

    private static function createRedisConnection(): \Redis
    {
        $cacheStore = (string) config('runtime_config.shared_store', 'redis');
        $connectionName = (string) config('cache.stores.'.$cacheStore.'.connection', 'default');
        $redisConfig = config('database.redis.'.$connectionName, []);

        $redis = new \Redis();
        $redis->connect(
            (string) ($redisConfig['host'] ?? '127.0.0.1'),
            (int) ($redisConfig['port'] ?? 6379),
            (float) ($redisConfig['timeout'] ?? 5.0)
        );
        $password = $redisConfig['password'] ?? null;
        if (is_string($password) && $password !== '') {
            $redis->auth($password);
        }
        $database = (int) ($redisConfig['database'] ?? 0);
        if ($database > 0) {
            $redis->select($database);
        }
        $redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);

        return $redis;
    }

    public static function handleMessage(RuntimeConfigSwooleStore $store, string $message): void
    {
        $payload = json_decode($message, true);
        if (!is_array($payload)
            || count($payload) !== 2
            || !array_key_exists('revision', $payload)
            || !array_key_exists('identities', $payload)
            || !is_int($payload['revision'] ?? null)
            || $payload['revision'] < 0
            || !is_array($payload['identities'] ?? null)
            || count($payload['identities']) > self::MAX_IDENTITIES) {
            return;
        }

        $keys = [];
        foreach ($payload['identities'] as $identity) {
            if (!is_array($identity)
                || count($identity) !== 1
                || !array_key_exists('swoole_key', $identity)
                || !is_string($identity['swoole_key'])
                || preg_match('/\Arc:[0-9a-f]{40}\z/D', $identity['swoole_key']) !== 1) {
                return;
            }
            $keys[] = $identity['swoole_key'];
        }

        foreach (array_unique($keys) as $key) {
            $store->deleteKey($key);
        }
    }

    public static function onReload(Server $swoole, Process $process)
    {
        $process->exit(0);
    }

    public static function onStop(Server $swoole, Process $process)
    {
        $process->exit(0);
    }
}
