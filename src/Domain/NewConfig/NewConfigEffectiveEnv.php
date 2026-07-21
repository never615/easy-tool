<?php

namespace Mallto\Tool\Domain\NewConfig;

use Dotenv\Parser\Parser;
use Throwable;

/**
 * NewConfigEffectiveEnv：配置中心发布与运行时生效链路的一环。
 * 它区分持久化配置、生成文件、配置缓存与长驻进程重载，避免调用方绕过发布边界。
 */
class NewConfigEffectiveEnv
{
    /**
     * 注入本组件运行所需的协作依赖，保持构造与业务执行解耦。
     */
    public function __construct(private NewConfigPublisher $publisher)
    {
    }

    /**
     * 处理「snapshot」相关的内部协作步骤。
     */
    public function snapshot(
        bool $mask = true,
        ?string $dotenvPath = null,
        ?array $processEnv = null,
        ?array $configCenterEnv = null
    ): array {
        $errors = [];
        $dotenvPath = $dotenvPath ?: base_path('.env');
        $dotenv = $this->dotenvValues($dotenvPath, $errors);
        $processEnv = $this->normalizeValues($processEnv ?? $this->processEnvValues());
        $configCenterEnv = $this->normalizeValues($configCenterEnv ?? $this->publisher->exportValues());

        $keys = array_unique(array_merge(array_keys($dotenv), array_keys($processEnv), array_keys($configCenterEnv)));
        sort($keys, SORT_STRING);

        $rows = [];
        foreach ($keys as $key) {
            $hasDotenv = array_key_exists($key, $dotenv);
            $hasProcess = array_key_exists($key, $processEnv);
            $hasConfigCenter = array_key_exists($key, $configCenterEnv);

            $finalSource = 'dotenv';
            $finalValue = $dotenv[$key] ?? null;
            if ($hasProcess) {
                $finalSource = 'process_env';
                $finalValue = $processEnv[$key];
            }
            if ($hasConfigCenter) {
                $finalSource = 'config_center';
                $finalValue = $configCenterEnv[$key];
            }

            $sensitive = $this->isSensitiveKey($key);
            $rows[] = [
                'key' => $key,
                'final_value' => $this->displayValue($finalValue, $mask && $sensitive),
                'final_source' => $finalSource,
                'final_source_label' => $this->sourceLabel($finalSource),
                'dotenv_value' => $hasDotenv ? $this->displayValue($dotenv[$key], $mask && $sensitive) : null,
                'process_value' => $hasProcess ? $this->displayValue($processEnv[$key], $mask && $sensitive) : null,
                'config_center_value' => $hasConfigCenter ? $this->displayValue($configCenterEnv[$key], $mask && $sensitive) : null,
                'has_dotenv' => $hasDotenv,
                'has_process_env' => $hasProcess,
                'has_config_center' => $hasConfigCenter,
                'sensitive' => $sensitive,
            ];
        }

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'masked' => $mask,
            'dotenv_path' => $dotenvPath,
            'runtime_env_path' => storage_path('framework/new_configs.env'),
            'counts' => [
                'dotenv' => count($dotenv),
                'process_env' => count($processEnv),
                'config_center' => count($configCenterEnv),
                'total' => count($rows),
            ],
            'errors' => $errors,
            'rows' => $rows,
        ];
    }

    /**
     * 处理「dotenvValues」相关的内部协作步骤。
     */
    private function dotenvValues(string $path, array &$errors): array
    {
        if (!is_file($path)) {
            return [];
        }

        try {
            $entries = (new Parser())->parse((string)file_get_contents($path));
        } catch (Throwable $exception) {
            $errors[] = [
                'source' => '.env',
                'message' => $exception->getMessage(),
            ];

            return [];
        }

        $values = [];
        foreach ($entries as $entry) {
            $key = $this->normalizeKey($entry->getName());
            if ($key === null) {
                continue;
            }

            $value = $entry->getValue();
            $values[$key] = $value->isDefined() ? $value->get()->getChars() : null;
        }

        ksort($values);

        return $values;
    }

    /**
     * 处理「processEnvValues」相关的内部协作步骤。
     */
    private function processEnvValues(): array
    {
        $env = getenv();

        return is_array($env) ? $env : [];
    }

    /**
     * 处理「normalizeValues」相关的内部协作步骤。
     */
    private function normalizeValues(array $values): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            $key = $this->normalizeKey($key);
            if ($key === null) {
                continue;
            }

            $normalized[$key] = $value === null ? null : (string)$value;
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * 处理「normalizeKey」相关的内部协作步骤。
     */
    private function normalizeKey($key): ?string
    {
        $key = strtoupper(trim((string)$key));
        if ($key === '' || preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) !== 1) {
            return null;
        }

        return $key;
    }

    /**
     * 处理「displayValue」相关的内部协作步骤。
     */
    private function displayValue(?string $value, bool $masked): ?string
    {
        if ($value === null) {
            return null;
        }

        return $masked ? '******' : $value;
    }

    /**
     * 处理「isSensitiveKey」相关的内部协作步骤。
     */
    private function isSensitiveKey(string $key): bool
    {
        if (preg_match('/^(DB|REDIS|MONGO|MONGODB)_/', $key) === 1) {
            return true;
        }

        return preg_match('/(^|_)(PASSWORD|PASS|SECRET|TOKEN|KEY|PRIVATE|CREDENTIAL|AUTH|COOKIE|SESSION)(_|$)/', $key) === 1;
    }

    /**
     * 处理「sourceLabel」相关的内部协作步骤。
     */
    private function sourceLabel(string $source): string
    {
        return match ($source) {
            'config_center' => '配置中心',
            'process_env' => '当前进程 env',
            default => '.env',
        };
    }
}
