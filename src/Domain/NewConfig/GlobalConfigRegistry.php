<?php

namespace Mallto\Tool\Domain\NewConfig;

class GlobalConfigRegistry
{
    private static array $modules = [];

    private static array $definitions = [];

    public static function registerModule(string $key, array $module): void
    {
        self::$modules[$key] = array_merge([
            'key' => $key,
        ], $module);
    }

    public static function registerDefinitions(array $definitions): void
    {
        foreach ($definitions as $definition) {
            $key = (string)($definition['key'] ?? '');
            if ($key === '') {
                continue;
            }

            self::$definitions[$key] = $definition;
        }
    }

    public static function modules(): array
    {
        return self::$modules;
    }

    public static function definitions(?string $module = null): array
    {
        if ($module === null) {
            return self::$definitions;
        }

        return array_filter(self::$definitions, function (array $definition) use ($module) {
            return ($definition['module'] ?? null) === $module;
        });
    }
}
