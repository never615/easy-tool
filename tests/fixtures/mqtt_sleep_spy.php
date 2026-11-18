<?php

namespace PhpMqtt\Client;

// 仅在隔离测试进程内使用，生产环境仍使用原生 usleep。
function usleep(int $microseconds): void
{
    $GLOBALS['mqtt_idle_sleeps'][] = $microseconds;
}
