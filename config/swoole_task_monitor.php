<?php

return [
    'enabled' => env('SWOOLE_TASK_MONITOR_ENABLED', false),
    'mode' => env('SWOOLE_TASK_MONITOR_MODE', 'summary'),
    'trace_sample_rate' => env('SWOOLE_TASK_MONITOR_TRACE_SAMPLE_RATE', null),
    // Null keeps the existing default Redis. Use an isolated connection for high-rate diagnostics.
    'redis_connection' => env('SWOOLE_TASK_MONITOR_REDIS_CONNECTION'),
    // Restart LaravelS; bounded worker timings are independent of trace sampling and business results.
    'execution_diagnostics' => [
        'enabled' => filter_var(env('SWOOLE_TASK_EXECUTION_DIAGNOSTICS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'run_id' => env('SWOOLE_TASK_EXECUTION_DIAGNOSTICS_RUN_ID', ''),
    ],
    // Diagnostic records only; full ingress and realtime PUT remain unchanged. Restart LaravelS.
    // Files are local to storage/logs/task-first-trace/<UUID>, never exported to Redis.
    'first_trace' => [
        'enabled' => filter_var(env('SWOOLE_TASK_FIRST_TRACE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'run_id' => env('SWOOLE_TASK_FIRST_TRACE_RUN_ID', ''),
        'per_channel' => 2,
    ],
];
