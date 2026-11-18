<?php

return [
    'shared_store' => env('RUNTIME_CONFIG_CACHE_STORE', 'redis'),
    'shared_ttl_seconds' => (int)env('RUNTIME_CONFIG_SHARED_TTL_SECONDS', 60),
    'identity_lock_seconds' => (int)env('RUNTIME_CONFIG_LOCK_SECONDS', 5),
    'swoole_table' => env('RUNTIME_CONFIG_SWOOLE_TABLE', 'runtime_config_cache'),
    'swoole_ttl_seconds' => (int)env('RUNTIME_CONFIG_SWOOLE_TTL_SECONDS', 60),
    // 默认关闭；开启后，由 AppStats 导出数量受限的 Pod 本地计数。
    'cache_metrics_enabled' => (bool)env('RUNTIME_CONFIG_CACHE_METRICS_ENABLED', false),
    'channel' => env('RUNTIME_CONFIG_INVALIDATION_CHANNEL', 'runtime_config:invalidate'),
    'revision_key' => env('RUNTIME_CONFIG_REVISION_KEY', 'runtime_config:revision'),
    'revision_poll_seconds' => (int)env('RUNTIME_CONFIG_REVISION_POLL_SECONDS', 5),
    // 仅供测试和本地使用；通过短期本地控制选择一个哈希身份，不使用环境变量项目列表。
    'branch_trace_enabled' => (bool)env('RUNTIME_CONFIG_BRANCH_TRACE_ENABLED', false),
    'branch_trace_control_path' => storage_path('framework/runtime-config-branch-control.json'),
    // 仅为冷缓存回填提供数量受限的本地证据；修改后需重启常驻进程。
    'refill_trace_enabled' => (bool)env('RUNTIME_CONFIG_REFILL_TRACE_ENABLED', false),
    'refill_trace_run_id' => env('RUNTIME_CONFIG_REFILL_TRACE_RUN_ID', ''),
    'refill_trace_directory' => env('RUNTIME_CONFIG_REFILL_TRACE_DIRECTORY', ''),
];
