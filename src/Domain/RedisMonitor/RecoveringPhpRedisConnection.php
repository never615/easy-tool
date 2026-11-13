<?php

namespace Mallto\Tool\Domain\RedisMonitor;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use RedisException;
use Throwable;

/** Discard an ambiguous RESP stream without replaying a possibly committed command. */
class RecoveringPhpRedisConnection extends PhpRedisConnection
{
    private bool $needsReconnect = false;

    public function client()
    {
        if ($this->needsReconnect && $this->connector !== null) {
            $this->client = ($this->connector)();
            $this->needsReconnect = false;
        }

        return $this->client;
    }

    public function command($method, array $parameters = [])
    {
        // Skip Laravel's eager reconnect, which can reuse an unclosed persistent socket.
        return $this->withRecovery(fn () => Connection::command($method, $parameters));
    }

    private function withRecovery(callable $operation)
    {
        $this->client();
        try {
            return $operation();
        } catch (RedisException $exception) {
            $this->disconnect();
            throw $exception;
        }
    }

    public function scan($cursor, $options = [])
    {
        return $this->withRecovery(fn () => parent::scan($cursor, $options));
    }

    public function zscan($key, $cursor, $options = [])
    {
        return $this->withRecovery(fn () => parent::zscan($key, $cursor, $options));
    }

    public function hscan($key, $cursor, $options = [])
    {
        return $this->withRecovery(fn () => parent::hscan($key, $cursor, $options));
    }

    public function sscan($key, $cursor, $options = [])
    {
        return $this->withRecovery(fn () => parent::sscan($key, $cursor, $options));
    }

    public function subscribe($channels, \Closure $callback)
    {
        return $this->withRecovery(fn () => parent::subscribe($channels, $callback));
    }

    public function psubscribe($channels, \Closure $callback)
    {
        return $this->withRecovery(fn () => parent::psubscribe($channels, $callback));
    }

    public function mget(array $keys)
    {
        $values = $this->command('mget', [$keys]);
        if (!is_array($values) || count($values) !== count($keys)) {
            $this->disconnect();
            throw new RedisException('Invalid Redis MGET response; connection discarded');
        }

        return array_map(static fn ($value) => $value === false ? null : $value, $values);
    }

    public function pipeline(?callable $callback = null)
    {
        try {
            $result = parent::pipeline($callback);
            if ($callback !== null && !is_array($result)) {
                throw new RedisException('Invalid Redis pipeline response; connection discarded');
            }
            return $result;
        } catch (Throwable $exception) {
            $this->disconnect();
            throw $exception;
        }
    }

    public function transaction(?callable $callback = null)
    {
        try {
            // false is a legitimate WATCH abort, so preserve it unchanged.
            return parent::transaction($callback);
        } catch (Throwable $exception) {
            $this->disconnect();
            throw $exception;
        }
    }

    public function disconnect()
    {
        $this->needsReconnect = true;
        try {
            $this->client->close();
        } catch (Throwable) {
            // Cleanup must preserve the command's original exception.
        }
    }
}
