<?php

namespace Mallto\Tool\Domain\NewConfig;

use Throwable;

class SubjectConfigRuntimeValues
{
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
