<?php

namespace Mallto\Tool\Domain\NewConfig;

use RuntimeException;

class NewConfigEnvFile
{
    private const MANAGED_START = '# >>> easy-tool new_configs';
    private const MANAGED_END = '# <<< easy-tool new_configs';

    public function write(array $values, ?string $filepath = null): array
    {
        $filepath = $filepath ?: base_path('.env');
        $values = $this->normalizeValues($values);
        $original = is_file($filepath) ? (string)file_get_contents($filepath) : '';
        $merged = $this->mergeContent($original, $values);

        if ($merged === $original) {
            return [
                'changed' => false,
                'backup_path' => null,
                'env_path' => $filepath,
            ];
        }

        $backupPath = null;
        if (is_file($filepath)) {
            $backupPath = $filepath . '.new_config.' . date('YmdHis') . '.' . getmypid();
            if (!copy($filepath, $backupPath)) {
                throw new RuntimeException("Failed to backup env file to {$backupPath}");
            }
        }

        $this->atomicWrite($filepath, $merged);

        return [
            'changed' => true,
            'backup_path' => $backupPath,
            'env_path' => $filepath,
        ];
    }

    public function mergeContent(string $content, array $values): string
    {
        $values = $this->normalizeValues($values);
        $lines = $content === '' ? [] : preg_split('/\r\n|\n|\r/', $content);
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        $lines = $this->withoutManagedSection($lines);
        $publishedKeys = [];
        $lines = array_map(function (string $line) use ($values, &$publishedKeys) {
            $key = $this->envLineKey($line);
            if ($key !== null && array_key_exists($key, $values)) {
                $publishedKeys[$key] = true;
                return $this->formatLine($key, $values[$key]);
            }

            return $line;
        }, $lines);

        $managedValues = array_diff_key($values, $publishedKeys);
        if ($managedValues !== []) {
            if ($lines !== [] && trim((string)end($lines)) !== '') {
                $lines[] = '';
            }

            $lines[] = self::MANAGED_START;
            foreach ($managedValues as $key => $value) {
                $lines[] = $this->formatLine($key, $value);
            }
            $lines[] = self::MANAGED_END;
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function withoutManagedSection(array $lines): array
    {
        $filtered = [];
        $insideManagedSection = false;

        foreach ($lines as $line) {
            $trimmed = trim((string)$line);
            if ($trimmed === self::MANAGED_START) {
                $insideManagedSection = true;
                continue;
            }

            if ($trimmed === self::MANAGED_END) {
                $insideManagedSection = false;
                continue;
            }

            if (!$insideManagedSection) {
                $filtered[] = (string)$line;
            }
        }

        return $filtered;
    }

    private function envLineKey(string $line): ?string
    {
        if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $matches) !== 1) {
            return null;
        }

        return strtoupper($matches[1]);
    }

    private function normalizeValues(array $values): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            $key = strtoupper(trim((string)$key));
            if ($key === '') {
                continue;
            }

            if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) !== 1) {
                throw new RuntimeException("Invalid env key [{$key}]");
            }

            $normalized[$key] = (string)$value;
        }

        ksort($normalized);

        return $normalized;
    }

    private function formatLine(string $key, string $value): string
    {
        return $key . '=' . $this->formatValue($value);
    }

    private function formatValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z0-9_.,:\/@%+\-=]+$/', $value) === 1) {
            return $value;
        }

        $escaped = str_replace(
            ["\\", "\"", "\n", "\r", '$'],
            ["\\\\", "\\\"", "\\n", "\\r", "\\$"],
            $value
        );

        return '"' . $escaped . '"';
    }

    private function atomicWrite(string $filepath, string $content): void
    {
        $directory = dirname($filepath);
        if (!is_dir($directory)) {
            throw new RuntimeException("Env directory does not exist: {$directory}");
        }

        $tmpPath = $directory . '/.' . basename($filepath) . '.new_config.' . getmypid() . '.tmp';
        if (file_put_contents($tmpPath, $content, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write temporary env file: {$tmpPath}");
        }

        if (is_file($filepath)) {
            @chmod($tmpPath, fileperms($filepath) & 0777);
        }

        if (!rename($tmpPath, $filepath)) {
            @unlink($tmpPath);
            throw new RuntimeException("Failed to replace env file: {$filepath}");
        }
    }
}
