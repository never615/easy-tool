<?php

namespace Mallto\Tool\Domain\NewConfig;

class NewConfigRuntimeValues
{
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
