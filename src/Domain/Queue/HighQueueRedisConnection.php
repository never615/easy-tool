<?php

namespace Mallto\Tool\Domain\Queue;

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\ConfigurationUrlParser;
use RuntimeException;

/** Separate process-owned socket to the exact same endpoint/DB/prefix, with bounded I/O. */
final class HighQueueRedisConnection
{
    private static int $pid = 0;
    private static array $connections = [];

    public static function forget(Factory $redis, ?string $name): void
    {
        $key = spl_object_id($redis).':'.($name ?? 'default');
        foreach ([$key, $key.':configured'] as $socketKey) {
            if (isset(self::$connections[$socketKey])) {
                try { self::$connections[$socketKey]->disconnect(); } catch (\Throwable) {}
                unset(self::$connections[$socketKey]);
            }
        }
    }

    public static function get(Factory $redis, ?string $name, bool $configuredTimeouts = false)
    {
        // Alternate factories (including test doubles) retain their own transport contract.
        if (!$redis instanceof RedisManager) return $redis->connection($name);
        if (self::$pid !== getmypid()) {
            self::$connections = [];
            self::$pid = getmypid();
        }
        $name ??= 'default';
        $key = spl_object_id($redis).':'.$name.($configuredTimeouts ? ':configured' : '');
        if (isset(self::$connections[$key])) return self::$connections[$key];
        if (config('database.redis.client', 'phpredis') !== 'phpredis') {
            throw new RuntimeException('Bounded queue admission requires standalone PhpRedis.');
        }
        $base = config('database.redis.'.$name);
        if (!$base) throw new RuntimeException('Queue admission Redis endpoint is not configured.');
        $base = (new ConfigurationUrlParser())->parseConfiguration($base);
        if (in_array(strtolower($base['driver'] ?? ''), ['tcp', 'tls'], true)) $base['scheme'] = strtolower($base['driver']);
        unset($base['driver']);
        $options = array_merge((array) config('database.redis.options', []), (array) ($base['options'] ?? []));
        // Preserve existing per-connection budgets for reliable queues (notably
        // Mongo history's 1s socket). Never allow an infinite admission wait.
        $timeout = $configuredTimeouts ? max(0.2, (float) ($options['timeout'] ?? $base['timeout'] ?? 1)) : 0.2;
        $readTimeout = $configuredTimeouts ? max(0.2, (float) ($options['read_timeout'] ?? $base['read_timeout'] ?? 1)) : 0.2;
        // phpredis 5.3.x accepts OPT_MAX_RETRIES only after connect().
        unset($base['max_retries'], $options['max_retries']);
        $base['options'] = array_merge($options, [
            'persistent' => false, 'timeout' => $timeout, 'read_timeout' => $readTimeout, 'retry_interval' => 0,
        ]);
        $connection = (new PhpRedisConnector())->connect($base, []);
        if (!$connection->client()->setOption(\Redis::OPT_MAX_RETRIES, 0)) {
            $connection->disconnect();
            throw new RuntimeException('Could not disable queue admission Redis reconnect retries.');
        }
        // No reconnect closure: an ambiguous write is never replayed. Recreate on the next probe.
        return self::$connections[$key] = new PhpRedisConnection($connection->client());
    }
}
