<?php

namespace Mallto\Tool\Domain\NewConfig;

use Throwable;

/**
 * SubjectConfigRuntimeValues：配置中心发布与运行时生效链路的一环。
 * 它区分持久化配置、生成文件、配置缓存与长驻进程重载，避免调用方绕过发布边界。
 */
class SubjectConfigRuntimeValues
{
    /**
     * 处理「load」相关的内部协作步骤。
     */
    public static function load(?string $filepath = null): array
    {
        $filepath = $filepath ?: storage_path('framework/subject_configs_values.php');
        if (!is_file($filepath) || !is_readable($filepath)) {
            return [];
        }

        try {
            $values = require $filepath;
        } catch (Throwable) {
            return [];
        }

        return is_array($values) ? self::normalizeValues($values) : [];
    }

    /**
     * 处理「normalizeValues」相关的内部协作步骤。
     */
    private static function normalizeValues(array $values): array
    {
        $normalized = [];
        foreach ($values as $subjectId => $configs) {
            $subjectId = (int)$subjectId;
            if ($subjectId <= 0 || !is_array($configs)) {
                continue;
            }

            $normalized[$subjectId] = [];
            foreach ($configs as $key => $value) {
                $key = trim((string)$key);
                if ($key === '') {
                    continue;
                }

                $normalized[$subjectId][$key] = $value === null ? null : (string)$value;
            }

            ksort($normalized[$subjectId]);
        }

        ksort($normalized);

        return $normalized;
    }
}
