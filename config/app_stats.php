<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

return [
    // null 有意选择 Laravel 默认 Redis 连接。多 Pod 发布时该连接必须指向共享 Redis；
    // 如果使用 Pod 本地 Redis，每个实例只能看到自己的快照，无法形成统一监控视图。
    'redis_connection' => null,
    'pods_hash_key' => 'swoole_stats:pods',
    'pods_last_seen_key' => 'swoole_stats:pods:last_seen',
    // task tmp 由独立常驻进程写入，不能与上面的 Swoole 快照共用 Hash，避免并发 HSET 丢字段。
    'task_tmp_pods_hash_key' => 'app_stats:swoole_task_tmp:pods',
    'task_tmp_pods_last_seen_key' => 'app_stats:swoole_task_tmp:pods:last_seen',
    'task_tmp_snapshot_stale_seconds' => 60,
    'collect_interval_seconds' => 5,
    'online_seconds' => 15,
    'delayed_seconds' => 60,
    'cleanup_seconds' => 300,
    'cleanup_interval_seconds' => 60,
    'page_refresh_seconds' => 5,
    'redis_memory_stale_seconds' => 120,
    'postgres_database_stale_seconds' => 120,
    'mongodb_database_stale_seconds' => 120,
];
