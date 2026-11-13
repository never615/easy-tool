<?php

namespace Mallto\Tool\Domain\RedisMonitor;

use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Support\Arr;

class RecoveringPhpRedisConnector extends PhpRedisConnector
{
    public function connect(array $config, array $options)
    {
        $formattedOptions = Arr::pull($config, 'options', []);
        if (isset($config['prefix'])) {
            $formattedOptions['prefix'] = $config['prefix'];
        }
        $connector = fn () => $this->createClient(array_merge($config, $options, $formattedOptions));

        return new RecoveringPhpRedisConnection($connector(), $connector, $config);
    }
}
