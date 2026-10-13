<?php

namespace PhpMqtt\Client;

// Required only inside isolated test processes; production still uses native usleep.
function usleep(int $microseconds): void
{
    $GLOBALS['mqtt_idle_sleeps'][] = $microseconds;
}
