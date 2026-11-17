<?php

// 路径规则以逗号或分号分隔；不区分 HTTP 方法。
$rules = static function ($value): array {
    return array_values(array_filter(array_map('trim', preg_split('/[;,]/', (string) $value)),
        static fn (string $rule): bool => $rule !== ''));
};
return [
    // 本层先于管理端规则运行：仅本地 config 匹配，不访问规则缓存。
    // 覆盖既有 app.log.except 高频路径，并补全实时/历史接口的实际前缀。
    'hard_exclude' => [
        'api/gateway/data', 'api/lbs/location_data', 'api/lbs/asyn/location',
        'api/lbs/location_result', 'api/lbs/online_location_result',
        'admin/api/fence_warning', 'admin/api/latest_fence_warning',
        'api/lora/lierda', 'admin/log',
        'api/tp/location_result', 'admin/api/location_result',
        'api/tp/lbs/location_result', 'api/tp/lbs/online_location_result',
        'api/lbs/location/trajectory-segments', 'api/tp/lbs/location/trajectory-segments',
        'api/lbs/location/histories', 'api/lbs/location/histories/*',
        'api/tp/lbs/location/histories', 'api/tp/lbs/location/histories/*',
        'api/lbs/history_locator', 'api/tp/lbs/history_locator', 'admin/api/lbs/history_locator',
    ],
    // 管理页基线规则：由下方 include 路径列表生成，默认每条启用。
    'admin_rules' => array_map(static fn (string $path): array => ['path' => $path, 'enabled' => true],
        $rules(env('OWNER_API_LOG_INCLUDE', ''))),
    // 仅白名单规则生效；空白名单不采集。
    'include' => $rules(env('OWNER_API_LOG_INCLUDE', '')),
    'retention_days' => env('OWNER_API_LOG_RETENTION_DAYS', 7),
    'cleanup_enabled' => filter_var(env('OWNER_API_LOG_CLEANUP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    'cleanup_at' => env('OWNER_API_LOG_CLEANUP_AT', '04:20'),
    'cleanup_batch_size' => env('OWNER_API_LOG_CLEANUP_BATCH_SIZE', 500),
    'max_text_bytes' => 65536,
];
