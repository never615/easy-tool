<?php

namespace Mallto\Tool\Domain\NewConfig;

/**
 * GlobalConfigDefinition：配置中心发布与运行时生效链路的一环。
 * 它区分持久化配置、生成文件、配置缓存与长驻进程重载，避免调用方绕过发布边界。
 */
class GlobalConfigDefinition
{
    public const BASELINE_LARAVEL_CONFIG = 'laravel_config';

    public const BASELINE_DATABASE = 'database';

    /**
     * 处理「make」相关的内部协作步骤。
     */
    public static function make(
        string $key,
        string $name,
        string $module,
        ?string $defaultValue,
        string $type,
        string $remark,
        ?string $envKey = null,
        array $meta = []
    ): array {
        return array_merge([
            'key' => $key,
            'env_key' => $envKey,
            'name' => $name,
            'module' => $module,
            'type' => $type,
            'default_value' => $defaultValue,
            'value' => $defaultValue,
            'options' => $type === 'boolean' ? '0,1' : null,
            'remark' => $remark,
            'sort' => 0,
            'ui' => $type === 'json' ? 'textarea' : 'input',
            'apply_mode' => $key === 'location_args'
                ? GlobalConfigApplyMode::SHARED_REDIS
                : GlobalConfigApplyMode::SWOOLE_REDIS,
            'requires_reload' => false,
            'baseline_source' => self::BASELINE_DATABASE,
        ], $meta);
    }

    public static function fromConfig(
        string $key,
        string $name,
        string $module,
        string $configKey,
        string $type,
        string $remark,
        ?string $envKey = null,
        array $meta = []
    ): array {
        if (!config()->has($configKey)) {
            throw new \LogicException("Missing Laravel config baseline: {$configKey}");
        }

        return self::make(
            $key,
            $name,
            $module,
            GlobalConfigBaselineResolver::normalize($type, config($configKey)),
            $type,
            $remark,
            $envKey,
            array_merge($meta, [
                'baseline_source' => self::BASELINE_LARAVEL_CONFIG,
                'config_key' => $configKey,
            ])
        );
    }

    public static function usesLaravelConfigBaseline(?array $definition): bool
    {
        return ($definition['baseline_source'] ?? null) === self::BASELINE_LARAVEL_CONFIG;
    }

    /**
     * 处理「keyByConfigKey」相关的内部协作步骤。
     */
    public static function keyByConfigKey(array $definitions): array
    {
        $keyed = [];

        foreach ($definitions as $definition) {
            $key = (string)($definition['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $keyed[$key] = $definition;
        }

        return $keyed;
    }
}
