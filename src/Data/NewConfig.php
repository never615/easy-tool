<?php

namespace Mallto\Tool\Data;

use Mallto\Tool\Domain\NewConfig\NewConfigBootstrapKeyGuard;
use Mallto\Tool\Domain\NewConfig\NewConfigPublisher;

class NewConfig extends BaseModel
{
    public const GROUP_SWOOLE_TASK_MONITOR = 'swoole_task_monitor';

    public const KEY_SWOOLE_TASK_MONITOR_ENABLED = 'swoole_task_monitor.enabled';
    public const KEY_SWOOLE_TASK_MONITOR_MODE = 'swoole_task_monitor.mode';
    public const KEY_SWOOLE_TASK_MONITOR_TRACE_SAMPLE_RATE = 'swoole_task_monitor.trace_sample_rate';

    protected $casts = [
        'is_enabled' => 'boolean',
        'requires_reload' => 'boolean',
        'last_published_at' => 'datetime',
    ];

    private static int $publishSuppressionCount = 0;

    protected static function booted(): void
    {
        static::saving(function (NewConfig $config) {
            $config->env_key = NewConfigBootstrapKeyGuard::normalize($config->env_key);
            NewConfigBootstrapKeyGuard::assertAllowed($config->env_key);
        });

        static::saved(function () {
            if (static::shouldAutoPublish()) {
                app(NewConfigPublisher::class)->publish(false);
            }
        });

        static::deleted(function () {
            if (static::shouldAutoPublish()) {
                app(NewConfigPublisher::class)->publish(false);
            }
        });
    }

    public static function withoutAutoPublish(callable $callback)
    {
        self::$publishSuppressionCount++;

        try {
            return $callback();
        } finally {
            self::$publishSuppressionCount--;
        }
    }

    private static function shouldAutoPublish(): bool
    {
        return self::$publishSuppressionCount <= 0 && !self::isTestRun();
    }

    private static function isTestRun(): bool
    {
        return app()->runningUnitTests()
            || defined('PHPUNIT_COMPOSER_INSTALL')
            || defined('__PHPUNIT_PHAR__')
            || class_exists(\PHPUnit\Framework\TestCase::class, false);
    }
}
