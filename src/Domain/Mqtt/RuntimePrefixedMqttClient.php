<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Mqtt;

use PhpMqtt\Client\Contracts\Repository;
use PhpMqtt\Client\Exceptions\ConfigurationInvalidException;
use PhpMqtt\Client\MqttClient;
use Psr\Log\LoggerInterface;

/**
 * RuntimePrefixedMqttClient：easy-tool 的 runtime prefixed mqtt client 组件。
 * 它通过明确的职责边界供包内其他模块复用。
 */
class RuntimePrefixedMqttClient extends MqttClient
{
    protected ?string $runtimeClientIdPrefix = null;

    protected int $runtimeClientIdMaxLength = 23;

    private int $idleSleepMicroseconds;

    /**
     * 注入本组件运行所需的协作依赖，保持构造与业务执行解耦。
     */
    public function __construct(
        string $host,
        int $port = 1883,
        ?string $clientId = null,
        string $protocol = self::MQTT_3_1,
        ?Repository $repository = null,
        ?LoggerInterface $logger = null,
        ?string $runtimeClientIdPrefix = null,
        int $runtimeClientIdMaxLength = 23,
        int $idleSleepMicroseconds = 100000
    )
    {
        $this->runtimeClientIdPrefix = $runtimeClientIdPrefix;
        $this->runtimeClientIdMaxLength = max(1, $runtimeClientIdMaxLength);
        if ($idleSleepMicroseconds < 5000 || $idleSleepMicroseconds > 100000) {
            throw new ConfigurationInvalidException('MQTT idle sleep must be between 5000 and 100000 microseconds.');
        }
        $this->idleSleepMicroseconds = $idleSleepMicroseconds;

        parent::__construct($host, $port, $clientId, $protocol, $repository, $logger);
    }

    public function loopOnce(float $loopStartedAt, bool $allowSleep = false, int $sleepMicroseconds = 100000): void
    {
        // Parent loop() omits the third argument. Preserve explicit caller overrides,
        // and let the parent sleep only when no socket data is available.
        if ($allowSleep && func_num_args() < 3) {
            $sleepMicroseconds = $this->idleSleepMicroseconds;
        }

        parent::loopOnce($loopStartedAt, $allowSleep, $sleepMicroseconds);
    }

    /**
     * 处理「generateRandomClientId」相关的内部协作步骤。
     */
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

    /**
     * 处理「normalizedClientIdPrefix」相关的内部协作步骤。
     */
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

        // Reserve four characters for the random suffix when the environment and
        // connection name are both present; a prefix containing only the environment
        // keeps the longer suffix through the calculation below.
        $maxPrefixLength = max(0, $this->runtimeClientIdMaxLength - 4 - 1);
        if ($maxPrefixLength === 0) {
            return '';
        }

        if (strlen($prefix) > $maxPrefixLength) {
            $prefix = rtrim(substr($prefix, 0, $maxPrefixLength), '-');
        }

        return $prefix;
    }
}
