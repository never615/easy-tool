<?php

return [
    'shared_store' => env('RUNTIME_CONFIG_CACHE_STORE', 'redis'),
    'shared_ttl_seconds' => (int)env('RUNTIME_CONFIG_SHARED_TTL_SECONDS', 60),
    'identity_lock_seconds' => (int)env('RUNTIME_CONFIG_LOCK_SECONDS', 5),
    'swoole_table' => env('RUNTIME_CONFIG_SWOOLE_TABLE', 'runtime_config_cache'),
    'swoole_ttl_seconds' => (int)env('RUNTIME_CONFIG_SWOOLE_TTL_SECONDS', 60),
    'channel' => env('RUNTIME_CONFIG_INVALIDATION_CHANNEL', 'runtime_config:invalidate'),
    'revision_key' => env('RUNTIME_CONFIG_REVISION_KEY', 'runtime_config:revision'),
    'revision_poll_seconds' => (int)env('RUNTIME_CONFIG_REVISION_POLL_SECONDS', 5),
    // Bounded local evidence for cold refills only; restart resident processes to change.
    'refill_trace_enabled' => (bool)env('RUNTIME_CONFIG_REFILL_TRACE_ENABLED', false),
    'refill_trace_run_id' => env('RUNTIME_CONFIG_REFILL_TRACE_RUN_ID', ''),
    'refill_trace_directory' => env('RUNTIME_CONFIG_REFILL_TRACE_DIRECTORY', ''),
];
