<?php

return [
    'restart' => [
        'strategy' => env('NEW_CONFIG_RESTART_STRATEGY', 'supervisor_autorestart'),
        'command' => env('NEW_CONFIG_RESTART_COMMAND'),
        'delay_seconds' => env('NEW_CONFIG_RESTART_DELAY_SECONDS', 2),
        'include_horizon' => env('NEW_CONFIG_RESTART_INCLUDE_HORIZON', true),
    ],
];
