<?php

use Mallto\Tool\Domain\NewConfig\NewConfigRuntimeValues;

return [
    'values_file' => env('NEW_CONFIG_VALUES_FILE', storage_path('framework/new_configs_values.php')),
    'values' => NewConfigRuntimeValues::load(env('NEW_CONFIG_VALUES_FILE', storage_path('framework/new_configs_values.php'))),

    'generation' => [
        'store' => env('NEW_CONFIG_GENERATION_STORE', 'redis'),
        'redis_connection' => env('NEW_CONFIG_GENERATION_REDIS_CONNECTION'),
        'key' => env('NEW_CONFIG_GENERATION_KEY', 'new_configs:apply_generation'),
    ],

    'watcher' => [
        'enabled' => env('NEW_CONFIG_WATCHER_ENABLED', true),
        'poll_seconds' => env('NEW_CONFIG_WATCHER_POLL_SECONDS', 10),
        'restart_strategy' => env('NEW_CONFIG_WATCHER_RESTART_STRATEGY', 'supervisor_autorestart'),
        'restart_command' => env('NEW_CONFIG_WATCHER_RESTART_COMMAND'),
    ],

    'restart' => [
        'strategy' => env('NEW_CONFIG_RESTART_STRATEGY', 'supervisor_autorestart'),
        'command' => env('NEW_CONFIG_RESTART_COMMAND'),
        'delay_seconds' => env('NEW_CONFIG_RESTART_DELAY_SECONDS', 2),
        'include_horizon' => env('NEW_CONFIG_RESTART_INCLUDE_HORIZON', true),
        'terminate_horizon' => env('NEW_CONFIG_RESTART_TERMINATE_HORIZON', true),
    ],
];
