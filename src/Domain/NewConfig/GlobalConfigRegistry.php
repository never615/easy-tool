<?php

namespace Mallto\Tool\Domain\NewConfig;

/**
 * GlobalConfigRegistry：配置中心发布与运行时生效链路的一环。
 * 它区分持久化配置、生成文件、配置缓存与长驻进程重载，避免调用方绕过发布边界。
 */
class GlobalConfigRegistry
{
    private static array $modules = [];

    private static array $definitions = [];

    /**
     * 处理「registerModule」相关的内部协作步骤。
     */
    public static function registerModule(string $key, array $module): void
    {
        self::$modules[$key] = array_merge([
            'key' => $key,
        ], $module);
    }

    /**
     * 处理「registerDefinitions」相关的内部协作步骤。
     */
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

    /**
     * 处理「modules」相关的内部协作步骤。
     */
    public static function modules(): array
    {
        return self::$modules;
    }

    /**
     * 处理「definitions」相关的内部协作步骤。
     */
    public static function definitions(?string $module = null): array
    {
        if ($module === null) {
            return self::$definitions;
        }

        return array_filter(self::$definitions, function (array $definition) use ($module) {
            return ($definition['module'] ?? null) === $module;
        });
    }

    public static function definition(string $key): ?array
    {
        return self::$definitions[$key] ?? null;
    }
}
