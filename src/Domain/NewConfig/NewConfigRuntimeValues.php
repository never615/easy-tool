<?php

namespace Mallto\Tool\Domain\NewConfig;

/**
 * NewConfigRuntimeValues：配置中心发布与运行时生效链路的一环。
 * 它区分持久化配置、生成文件、配置缓存与长驻进程重载，避免调用方绕过发布边界。
 */
class NewConfigRuntimeValues
{
    /**
     * 处理「load」相关的内部协作步骤。
     */
    public static function load(?string $filepath = null): array
    {
        $filepath = $filepath ?: storage_path('framework/new_configs_values.php');
        if (!is_file($filepath) || !is_readable($filepath)) {
            return [];
        }

        $values = require $filepath;

        return is_array($values) ? $values : [];
    }
}
