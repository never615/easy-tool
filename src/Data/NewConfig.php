<?php

namespace Mallto\Tool\Data;

use Mallto\Tool\Domain\NewConfig\NewConfigBootstrapKeyGuard;

/**
 * NewConfig：new config 对应的数据模型或持久化边界。
 * 它集中声明字段、关联和查询语义，供领域服务与管理界面复用。
 */
class NewConfig extends BaseModel
{
    public const GROUP_GLOBAL_CONFIG = 'global_config';

    public const GROUP_SWOOLE_TASK_MONITOR = 'swoole_task_monitor';

    public const GROUP_RUNTIME_ENV_OVERRIDE = 'runtime_env_override';

    public const KEY_SWOOLE_TASK_MONITOR_ENABLED = 'swoole_task_monitor.enabled';
    public const KEY_SWOOLE_TASK_MONITOR_MODE = 'swoole_task_monitor.mode';
    public const KEY_SWOOLE_TASK_MONITOR_TRACE_SAMPLE_RATE = 'swoole_task_monitor.trace_sample_rate';

    protected $casts = [
        'is_enabled' => 'boolean',
        'requires_reload' => 'boolean',
        'last_published_at' => 'datetime',
    ];

    private static int $publishSuppressionCount = 0;

    private static int $testAutoPublishOverrideCount = 0;

    /** @var array{changed: bool, old_key: string, old_group: string}|null */
    public ?array $runtimeConfigChangeContext = null;

    /**
     * 处理「booted」相关的内部协作步骤。
     */
    protected static function booted(): void
    {
        static::saving(function (NewConfig $config) {
            $config->env_key = NewConfigBootstrapKeyGuard::normalize($config->env_key);
            if ($config->group_key === self::GROUP_RUNTIME_ENV_OVERRIDE) {
                NewConfigBootstrapKeyGuard::assertAllowedForRuntimeOverride($config->env_key);
            } else {
                NewConfigBootstrapKeyGuard::assertAllowed($config->env_key);
            }
        });

    }

    /**
     * 处理「runtimeEnvOverrideKey」相关的内部协作步骤。
     */
    public static function runtimeEnvOverrideKey(string $envKey): string
    {
        $envKey = NewConfigBootstrapKeyGuard::normalize($envKey);

        return self::GROUP_RUNTIME_ENV_OVERRIDE . '.' . $envKey;
    }

    /**
     * 处理「withoutAutoPublish」相关的内部协作步骤。
     */
    public static function withoutAutoPublish(callable $callback)
    {
        self::$publishSuppressionCount++;

        try {
            return $callback();
        } finally {
            self::$publishSuppressionCount--;
        }
    }

    /**
     * 仅允许单元测试临时走生产 auto-publish 判断，用于确定性验证 after-commit 分流。
     */
    public static function withAutoPublishForTesting(callable $callback)
    {
        if (!self::isTestRun()) {
            throw new \LogicException('Auto-publish test override is only available during unit tests.');
        }

        self::$testAutoPublishOverrideCount++;

        try {
            return $callback();
        } finally {
            self::$testAutoPublishOverrideCount--;
        }
    }

    /**
     * 处理「shouldAutoPublish」相关的内部协作步骤。
     */
    public static function shouldAutoPublish(): bool
    {
        return self::$publishSuppressionCount <= 0
            && (!self::isTestRun() || self::$testAutoPublishOverrideCount > 0);
    }

    /**
     * 处理「isTestRun」相关的内部协作步骤。
     */
    private static function isTestRun(): bool
    {
        return app()->runningUnitTests()
            || defined('PHPUNIT_COMPOSER_INSTALL')
            || defined('__PHPUNIT_PHAR__')
            || class_exists(\PHPUnit\Framework\TestCase::class, false);
    }
}
