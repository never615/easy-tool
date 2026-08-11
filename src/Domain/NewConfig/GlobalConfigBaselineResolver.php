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
     */
    public static function normalize(string $type, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'integer' => (string)((int)$value),
            'float' => (string)((float)$value),
            'json' => is_string($value)
                ? $value
                : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => (string)$value,
        };
    }
}
