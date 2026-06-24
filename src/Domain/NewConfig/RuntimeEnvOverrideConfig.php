<?php

namespace Mallto\Tool\Domain\NewConfig;

use Mallto\Tool\Data\NewConfig;
use Mallto\Tool\Exception\ResourceException;

class RuntimeEnvOverrideConfig
{
    public const TYPES = [
        'boolean' => 'boolean',
        'integer' => 'integer',
        'float' => 'float',
        'string' => 'string',
    ];

    public static function attributesFor(
        string $envKey,
        string $name,
        string $type,
        ?string $value,
        ?string $defaultValue,
        ?string $remark,
        bool $isEnabled
    ): array {
        $envKey = self::normalizeEnvKey($envKey);
        $type = self::normalizeType($type);

        return [
            'key' => NewConfig::runtimeEnvOverrideKey($envKey),
            'env_key' => $envKey,
            'group_key' => NewConfig::GROUP_RUNTIME_ENV_OVERRIDE,
            'name' => trim($name) !== '' ? trim($name) : $envKey,
            'type' => $type,
            'value' => self::normalizeValue($type, $value),
            'default_value' => self::normalizeValue($type, $defaultValue),
            'options' => $type === 'boolean' ? '0,1' : null,
            'remark' => $remark === null ? null : trim($remark),
            'sort' => 0,
            'is_enabled' => $isEnabled,
            'requires_reload' => true,
        ];
    }

    public static function assertUniqueEnvKey(string $envKey, ?int $ignoreId = null): void
    {
        $envKey = self::normalizeEnvKey($envKey);
        $query = NewConfig::query()->where('env_key', $envKey);

        if ($ignoreId !== null) {
            $query->where('id', '<>', $ignoreId);
        }

        if ($query->exists()) {
            throw new ResourceException("Env Key [{$envKey}] 已存在，不能重复创建运行期 Env 覆盖。");
        }
    }

    public static function normalizeEnvKey(string $envKey): string
    {
        $envKey = NewConfigBootstrapKeyGuard::normalize($envKey);
        if ($envKey === null || preg_match('/^[A-Z_][A-Z0-9_]*$/', $envKey) !== 1) {
            throw new ResourceException('Env Key 必须是大写字母、数字、下划线，且以字母或下划线开头。');
        }

        NewConfigBootstrapKeyGuard::assertAllowed($envKey);

        return $envKey;
    }

    private static function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        if (!array_key_exists($type, self::TYPES)) {
            throw new ResourceException('运行期 Env 覆盖类型无效，只允许 boolean、integer、float、string。');
        }

        return $type;
    }

    private static function normalizeValue(string $type, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $type === 'boolean' ? '0' : null;
        }

        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'integer' => (string)((int)$value),
            'float' => (string)((float)$value),
            default => (string)$value,
        };
    }
}
