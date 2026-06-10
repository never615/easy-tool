<?php

namespace Mallto\Tool\Data;

use Mallto\Tool\Domain\NewConfig\NewConfigCenter;

class NewConfig extends BaseModel
{
    public const GROUP_SWOOLE_TASK_MONITOR = 'swoole_task_monitor';

    public const KEY_SWOOLE_TASK_MONITOR_ENABLED = 'swoole_task_monitor.enabled';
    public const KEY_SWOOLE_TASK_MONITOR_MODE = 'swoole_task_monitor.mode';
    public const KEY_SWOOLE_TASK_MONITOR_TRACE_SAMPLE_RATE = 'swoole_task_monitor.trace_sample_rate';

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(function () {
            app(NewConfigCenter::class)->clearCache();
        });

        static::deleted(function () {
            app(NewConfigCenter::class)->clearCache();
        });
    }
}
