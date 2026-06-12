<?php

namespace Mallto\Tool\Domain\NewConfig;

use Mallto\Tool\Data\NewConfig;

class GlobalConfigNewConfig
{
    private const ENV_PREFIX = 'GLOBAL_CONFIG_';

    public static function envKeyFor(string $key): string
    {
        $slug = strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', '_', $key));
        $slug = trim($slug, '_');
        if ($slug === '') {
            $slug = 'VALUE';
        }

        return self::ENV_PREFIX . $slug . '_' . strtoupper(substr(sha1($key), 0, 8));
    }

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
            'requires_reload' => true,
        ];
    }

    public static function attributesForDefinition(array $definition, ?string $value = null): array
    {
        $key = (string)$definition['key'];
        $attributes = self::attributesFor(
            $key,
            $value ?? (string)($definition['value'] ?? $definition['default_value'] ?? ''),
            (string)($definition['remark'] ?? '')
        );

        $attributes['name'] = (string)($definition['name'] ?? $attributes['name']);
        $attributes['type'] = (string)($definition['type'] ?? $attributes['type']);
        $attributes['default_value'] = (string)($definition['default_value'] ?? $attributes['default_value']);
        $attributes['options'] = $definition['options'] ?? $attributes['options'];
        $attributes['sort'] = (int)($definition['sort'] ?? $attributes['sort']);

        return $attributes;
    }
}
