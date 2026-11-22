<?php

namespace Mallto\Tool\Domain\Queue;

use Illuminate\Contracts\Queue\Factory;
use Illuminate\Queue\Connectors\ConnectorInterface;

final class SharedHorizonConnector implements ConnectorInterface
{
    public function __construct(private Factory $queues)
    {
    }

    public function connect(array $config)
    {
        return new SharedHorizonQueue($this->queues, config('horizon.shared_queue_routes.'.$config['pool']));
    }
}
