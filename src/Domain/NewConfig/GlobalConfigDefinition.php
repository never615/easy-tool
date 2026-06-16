<?php

namespace Mallto\Tool\Domain\NewConfig;

class GlobalConfigDefinition
{
    public static function make(
        string $key,
        string $name,
        string $module,
        string $defaultValue,
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
        ], $meta);
    }

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
