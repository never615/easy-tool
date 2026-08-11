<?php

namespace Mallto\Tool\Domain\NewConfig;

use Mallto\Tool\Data\NewConfig;

/**
 * GlobalConfigNewConfig：配置中心发布与运行时生效链路的一环。
 * 它区分持久化配置、生成文件、配置缓存与长驻进程重载，避免调用方绕过发布边界。
 */
class GlobalConfigNewConfig
{
    private const ENV_PREFIX = 'GLOBAL_CONFIG_';

    /**
     * 处理「envKeyFor」相关的内部协作步骤。
     */
    public static function envKeyFor(string $key): string
    {
        $slug = strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', '_', $key));
        $slug = trim($slug, '_');
        if ($slug === '') {
            $slug = 'VALUE';
        }

        return self::ENV_PREFIX . $slug . '_' . strtoupper(substr(sha1($key), 0, 8));
    }

    /**
     * 处理「envKeyForDefinition」相关的内部协作步骤。
     */
    public static function envKeyForDefinition(array $definition): string
    {
        $envKey = (string)($definition['env_key'] ?? '');
        if ($envKey !== '') {
            return $envKey;
        }

        return self::envKeyFor((string)$definition['key']);
    }

    /**
     * 处理「attributesFor」相关的内部协作步骤。
     */
    public static function attributesFor(string $key, ?string $value = null, ?string $remark = null): array
    {
        return [
            'key' => $key,
            'env_key' => self::envKeyFor($key),
            'group_key' => NewConfig::GROUP_GLOBAL_CONFIG,
            'name' => $remark ?: $key,
            'type' => 'string',
            'value' => $value,
            'default_value' => null,
            'options' => null,
            'remark' => $remark,
            'sort' => 0,
            'is_enabled' => true,
            'override_source' => null,
            'requires_reload' => GlobalConfigApplyMode::requiresReload($key),
        ];
    }

    /**
     * 处理「attributesForDefinition」相关的内部协作步骤。
     */
    public static function attributesForDefinition(array $definition, ?string $value = null): array
    {
        $key = (string)$definition['key'];
        $attributes = self::attributesFor(
            $key,
            $value,
            (string)($definition['remark'] ?? '')
        );

        $attributes['name'] = (string)($definition['name'] ?? $attributes['name']);
        $attributes['env_key'] = self::envKeyForDefinition($definition);
        $attributes['type'] = (string)($definition['type'] ?? $attributes['type']);
        $attributes['default_value'] = array_key_exists('default_value', $definition)
            ? $definition['default_value']
            : $attributes['default_value'];
        $attributes['options'] = $definition['options'] ?? $attributes['options'];
        $attributes['sort'] = (int)($definition['sort'] ?? $attributes['sort']);
        $attributes['requires_reload'] = false;

        return $attributes;
    }
}
