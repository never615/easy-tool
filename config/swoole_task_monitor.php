<?php

return [
    'enabled' => env('SWOOLE_TASK_MONITOR_ENABLED', true),
    'mode' => env('SWOOLE_TASK_MONITOR_MODE', 'summary'),
    'trace_sample_rate' => env('SWOOLE_TASK_MONITOR_TRACE_SAMPLE_RATE', null),
];
