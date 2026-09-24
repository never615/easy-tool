<?php

namespace Mallto\Tool\Tests\Unit;

use Mallto\Tool\Domain\Mqtt\RuntimePrefixedMqttClient;
use PHPUnit\Framework\TestCase;

class RuntimePrefixedMqttClientTest extends TestCase
{
    public function test_generated_client_id_contains_the_connection_name_in_the_runtime_prefix(): void
    {
        $client = new RuntimePrefixedMqttClient(
            '127.0.0.1',
            1883,
            null,
            RuntimePrefixedMqttClient::MQTT_3_1_1,
            null,
            null,
            'prod-hjy_pub',
            23
        );

        $this->assertStringStartsWith('prod-hjy-pub-', $client->getClientId());
        $this->assertLessThanOrEqual(23, strlen($client->getClientId()));
    }

    public function test_explicit_client_id_is_not_rewritten(): void
    {
        $client = new RuntimePrefixedMqttClient(
            '127.0.0.1',
            1883,
            'configured-client',
            RuntimePrefixedMqttClient::MQTT_3_1_1,
            null,
            null,
            'production-skylab',
            23
        );

        $this->assertSame('configured-client', $client->getClientId());
    }
}
