<?php

namespace Mallto\Tool\Data;

/**
 * QueueDiagnosticSnapshot：queue diagnostic snapshot 对应的数据模型或持久化边界。
 * 它集中声明字段、关联和查询语义，供领域服务与管理界面复用。
 */
class QueueDiagnosticSnapshot extends BaseModel
{
    protected $casts = [
        'window_started_at' => 'datetime',
        'queue_sizes' => 'array',
        'top_jobs' => 'array',
        'sources' => 'array',
        'source_groups' => 'array',
        'slow_jobs' => 'array',
        'large_payload_jobs' => 'array',
        'failed_jobs' => 'array',
        'backlog_sample' => 'array',
        'keyspace' => 'array',
        'scan_patterns' => 'array',
        'anomaly_reasons' => 'array',
    ];
}
