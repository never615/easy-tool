<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Mqtt;

use PhpMqtt\Client\Contracts\Repository;
use PhpMqtt\Client\MqttClient;
use Psr\Log\LoggerInterface;

class RuntimePrefixedMqttClient extends MqttClient
{
    protected ?string $runtimeClientIdPrefix = null;

    protected int $runtimeClientIdMaxLength = 23;

    public function __construct(
        string $host,
        int $port = 1883,
        ?string $clientId = null,
        string $protocol = self::MQTT_3_1,
        ?Repository $repository = null,
        ?LoggerInterface $logger = null,
        ?string $runtimeClientIdPrefix = null,
        int $runtimeClientIdMaxLength = 23
    )
    {
        $this->runtimeClientIdPrefix = $runtimeClientIdPrefix;
        $this->runtimeClientIdMaxLength = max(1, $runtimeClientIdMaxLength);

        parent::__construct($host, $port, $clientId, $protocol, $repository, $logger);
    }

    protected function generateRandomClientId(): string
    {
        $randomClientId = parent::generateRandomClientId();
        $prefix = $this->normalizedClientIdPrefix();

        if ($prefix === '') {
            return substr($randomClientId, 0, $this->runtimeClientIdMaxLength);
        }

        $suffixLength = max(1, $this->runtimeClientIdMaxLength - strlen($prefix) - 1);

        return $prefix . '-' . substr($randomClientId, 0, $suffixLength);
    }

    protected function normalizedClientIdPrefix(): string
    {
        $prefix = trim((string)$this->runtimeClientIdPrefix);
        if ($prefix === '') {
            return '';
        }

        $prefix = strtolower($prefix);
        $prefix = preg_replace('/[^a-z0-9]+/', '-', $prefix);
        $prefix = trim((string)$prefix, '-');

        if ($prefix === '') {
            return '';
        }

        // Keep enough random suffix entropy while staying inside conservative broker limits.
        $maxPrefixLength = max(0, $this->runtimeClientIdMaxLength - 12 - 1);
        if ($maxPrefixLength === 0) {
            return '';
        }

        if (strlen($prefix) > $maxPrefixLength) {
            $prefix = rtrim(substr($prefix, 0, $maxPrefixLength), '-');
        }

        return $prefix;
    }
}
