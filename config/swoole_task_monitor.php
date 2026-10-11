<?php

return [
    'enabled' => env('SWOOLE_TASK_MONITOR_ENABLED', false),
    'mode' => env('SWOOLE_TASK_MONITOR_MODE', 'summary'),
    'trace_sample_rate' => env('SWOOLE_TASK_MONITOR_TRACE_SAMPLE_RATE', null),
    // Null keeps the existing default Redis. Use an isolated connection for high-rate diagnostics.
    'redis_connection' => env('SWOOLE_TASK_MONITOR_REDIS_CONNECTION'),
];
