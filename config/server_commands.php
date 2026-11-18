<?php

return [
    'enabled' => env('SERVER_COMMANDS_ENABLED', false),
    'default_timeout' => 60,
    'max_timeout' => 300,
    'max_input_bytes' => 16384,
    'max_output_bytes' => 262144,
    'body_retention_days' => 7,
    'record_retention_days' => 90,
];
