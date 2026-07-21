<?php

namespace Mallto\Tool\Domain\NewConfig;

use RuntimeException;

/**
 * SubjectConfigValuesFile：配置中心发布与运行时生效链路的一环。
 * 它区分持久化配置、生成文件、配置缓存与长驻进程重载，避免调用方绕过发布边界。
 */
class SubjectConfigValuesFile
{
    /**
     * 处理「write」相关的内部协作步骤。
     */
    public function write(array $values, ?string $filepath = null): array
    {
        $filepath = $filepath ?: storage_path('framework/subject_configs_values.php');
        $values = $this->normalizeValues($values);
        $content = $this->renderContent($values);

        if (is_file($filepath) && is_readable($filepath) && $content === (string)file_get_contents($filepath)) {
            return [
                'changed' => false,
                'values_path' => $filepath,
            ];
        }

        $this->atomicWrite($filepath, $content);

        return [
            'changed' => true,
            'values_path' => $filepath,
        ];
    }

    /**
     * 处理「renderContent」相关的内部协作步骤。
     */
    public function renderContent(array $values): string
    {
        $values = $this->normalizeValues($values);

        return "<?php\n\nreturn " . var_export($values, true) . ";\n";
    }

    /**
     * 处理「normalizeValues」相关的内部协作步骤。
     */
    private function normalizeValues(array $values): array
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

    /**
     * 处理「atomicWrite」相关的内部协作步骤。
     */
    private function atomicWrite(string $filepath, string $content): void
    {
        $directory = dirname($filepath);
        if (!is_dir($directory)) {
            throw new RuntimeException("Subject config values directory does not exist: {$directory}");
        }

        $tmpPath = $directory . '/.' . basename($filepath) . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmpPath, $content, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write temporary subject config values file: {$tmpPath}");
        }

        $directoryGroup = @filegroup($directory);
        if ($directoryGroup !== false) {
            @chgrp($tmpPath, $directoryGroup);
        }
        @chmod($tmpPath, 0660);

        if (!rename($tmpPath, $filepath)) {
            @unlink($tmpPath);
            throw new RuntimeException("Failed to replace subject config values file: {$filepath}");
        }

        @chmod($filepath, 0660);
    }
}
