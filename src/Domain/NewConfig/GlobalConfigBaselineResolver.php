<?php

namespace Mallto\Tool\Domain\NewConfig;

use JsonException;

final class GlobalConfigBaselineResolver
{
    public function forDefinition(array $definition): mixed
    {
        if (($definition['baseline_source'] ?? null) === 'laravel_config') {
            $configKey = trim((string)($definition['config_key'] ?? ''));
            if ($configKey === '' || !config()->has($configKey)) {
                throw new \LogicException("Missing Laravel config baseline: {$configKey}");
            }

            return self::normalize((string)($definition['type'] ?? 'string'), config($configKey));
        }

        return $definition['default_value'] ?? null;
    }

    /**
     * @throws JsonException
     * @throws \InvalidArgumentException
     */
    public static function normalize(string $type, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => self::normalizeBoolean($value),
            'integer' => (string)((int)$value),
            'float' => (string)((float)$value),
            'json' => is_string($value)
                ? $value
                : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => (string)$value,
        };
    }

    /**
     * Boolean 配置只能接受明确的布尔表示，不能把拼写错误静默写成 false。
     */
    private static function normalizeBoolean(mixed $value): string
    {
        if (!is_bool($value) && !is_int($value) && !is_string($value)) {
            throw new \InvalidArgumentException('Boolean global config value is invalid.');
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new \InvalidArgumentException('Boolean global config value is invalid.');
        }

        return $parsed ? '1' : '0';
    }
}
