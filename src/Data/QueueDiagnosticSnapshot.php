<?php

namespace Mallto\Tool\Data;

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
        'keyspace' => 'array',
        'scan_patterns' => 'array',
        'anomaly_reasons' => 'array',
    ];
}
