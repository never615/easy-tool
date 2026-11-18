<?php

return [
    'enabled' => env('SWOOLE_TASK_MONITOR_ENABLED', false),
    'mode' => env('SWOOLE_TASK_MONITOR_MODE', 'summary'),
    'trace_sample_rate' => env('SWOOLE_TASK_MONITOR_TRACE_SAMPLE_RATE', null),
    // null 保留现有默认 Redis。高频诊断应使用隔离连接。
    'redis_connection' => env('SWOOLE_TASK_MONITOR_REDIS_CONNECTION'),
    // 需重启 LaravelS；数量受限的工作进程计时独立于追踪采样和业务结果。
    'execution_diagnostics' => [
        'enabled' => filter_var(env('SWOOLE_TASK_EXECUTION_DIAGNOSTICS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'run_id' => env('SWOOLE_TASK_EXECUTION_DIAGNOSTICS_RUN_ID', ''),
    ],
    // 仅记录诊断数据，全部入口流量与实时 PUT 不变。需重启 LaravelS。
    // 文件仅存储在本地 storage/logs/task-first-trace/<UUID>，不导出至 Redis。
    'first_trace' => [
        'enabled' => filter_var(env('SWOOLE_TASK_FIRST_TRACE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'run_id' => env('SWOOLE_TASK_FIRST_TRACE_RUN_ID', ''),
        'per_channel' => 2,
    ],
];
