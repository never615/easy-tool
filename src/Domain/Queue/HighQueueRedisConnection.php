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
        if (isset(self::$connections[$key])) {
            try { self::$connections[$key]->disconnect(); } catch (\Throwable) {}
            unset(self::$connections[$key]);
        }
    }

    public static function get(Factory $redis, ?string $name)
    {
        // Alternate factories (including test doubles) retain their own transport contract.
        if (!$redis instanceof RedisManager) return $redis->connection($name);
        if (self::$pid !== getmypid()) {
            self::$connections = [];
            self::$pid = getmypid();
        }
        $name ??= 'default';
        $key = spl_object_id($redis).':'.$name;
        if (isset(self::$connections[$key])) return self::$connections[$key];
        if (config('database.redis.client', 'phpredis') !== 'phpredis') {
            throw new RuntimeException('Bounded high admission requires standalone PhpRedis.');
        }
        $base = config('database.redis.'.$name);
        if (!$base) throw new RuntimeException('High admission Redis endpoint is not configured.');
        $base = (new ConfigurationUrlParser())->parseConfiguration($base);
        if (in_array(strtolower($base['driver'] ?? ''), ['tcp', 'tls'], true)) $base['scheme'] = strtolower($base['driver']);
        unset($base['driver']);
        $options = array_merge((array) config('database.redis.options', []), (array) ($base['options'] ?? []));
        // phpredis 5.3.x accepts OPT_MAX_RETRIES only after connect().
        unset($base['max_retries'], $options['max_retries']);
        $base['options'] = array_merge($options, [
            'persistent' => false, 'timeout' => 0.2, 'read_timeout' => 0.2, 'retry_interval' => 0,
        ]);
        $connection = (new PhpRedisConnector())->connect($base, []);
        if (!$connection->client()->setOption(\Redis::OPT_MAX_RETRIES, 0)) {
            $connection->disconnect();
            throw new RuntimeException('Could not disable high admission Redis reconnect retries.');
        }
        // No reconnect closure: an ambiguous write is never replayed. Recreate on the next probe.
        return self::$connections[$key] = new PhpRedisConnection($connection->client());
    }
}
