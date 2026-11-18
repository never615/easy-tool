<?php
/**
 * 版权所有 (c) 2026. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

return [
    'params' => [
        'cache_store' => env('WEBVIEW_PARAM_CACHE_STORE', 'redis'),
        'ttl_seconds' => (int)env('WEBVIEW_PARAM_TTL_SECONDS', 86400),
        'max_payload_bytes' => (int)env('WEBVIEW_PARAM_MAX_PAYLOAD_BYTES', 262144),
    ],
];
