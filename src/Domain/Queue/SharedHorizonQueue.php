<?php

namespace Mallto\Tool\Domain\Queue;

use Illuminate\Contracts\Queue\Factory;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;
use Laravel\Horizon\RedisQueue;
use LogicException;
use Predis\Connection\ConnectionException;
use RedisException;

final class SharedHorizonQueue extends Queue implements QueueContract
{
    private array $retryAt = [];

    public function __construct(private Factory $queues, private array $routes)
    {
    }

    public function optionsFor(string $queue): array
    {
        return $this->routes[$queue] ?? throw new LogicException("Unknown shared Horizon queue [{$queue}].");
    }

    public function readyNow($queue = null)
    {
        return $this->readRoute($queue, fn ($connection) => $connection->readyNow($queue)) ?? 0;
    }

    public function size($queue = null)
    {
        return $this->connectionFor($queue)->size($queue);
    }

    public function pop($queue = null, $index = 0)
    {
        // A blocking pop on any one route would delay the other routes.
        return $this->readRoute($queue, fn ($connection) => $connection->pop($queue, 1));
    }

    public function push($job, $data = '', $queue = null)
    {
        return $this->connectionFor($queue)->push($job, $data, $queue);
    }

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        return $this->connectionFor($queue)->pushRaw($payload, $queue, $options);
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->connectionFor($queue)->later($delay, $job, $data, $queue);
    }

    public function clear($queue)
    {
        return $this->connectionFor($queue)->clear($queue);
    }

    private function connectionFor(?string $queue): RedisQueue
    {
        $route = $this->optionsFor($queue ?? '');
        return $this->queues->connection($route['connection']);
    }

    private function readRoute(string $queue, callable $operation): mixed
    {
        $name = $this->optionsFor($queue)['connection'];
        if (($this->retryAt[$name] ?? 0) > hrtime(true)) {
            return null;
        }

        try {
            return $operation($this->connectionFor($queue));
        } catch (RedisException | ConnectionException $exception) {
            // A lost response may have reserved a job. Its original lease handles recovery.
            $this->retryAt[$name] = hrtime(true) + 5_000_000_000;
            report($exception);
            return null;
        }
    }
}
