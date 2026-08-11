<?php

namespace Mallto\Tool\Domain\NewConfig;

/**
 * 汇总各业务包显式注册的全局配置 Definitions。
 *
 * Registry 不扫描 ConfigUtils::get() 调用；它只在运行时接收开发阶段已经通过
 * AI 辅助源码盘点生成并固化在各包 Definitions 类中的结果。
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
