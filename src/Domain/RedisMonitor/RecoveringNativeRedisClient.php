<?php

namespace Mallto\Tool\Domain\RedisMonitor;

use RedisException;

/** 保留原生签名（尤其是 EVAL），同时隔离故障套接字。 */
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
