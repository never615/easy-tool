<?php

namespace Mallto\Tool\Domain\RedisMonitor;

use RedisException;

/** Retain native signatures (notably EVAL) while fencing a failed socket. */
final class RecoveringNativeRedisClient
{
    public function __construct(private readonly object $connection)
    {
    }

    public function __call(string $method, array $arguments): mixed
    {
        try {
            return $this->connection->client()->$method(...$arguments);
        } catch (RedisException $exception) {
            $this->connection->disconnect();
            throw $exception;
        }
    }
}
