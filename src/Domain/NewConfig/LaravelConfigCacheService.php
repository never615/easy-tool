<?php

namespace Mallto\Tool\Domain\NewConfig;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * LaravelConfigCacheService：配置中心发布与运行时生效链路的一环。
 * 它区分持久化配置、生成文件、配置缓存与长驻进程重载，避免调用方绕过发布边界。
 */
class LaravelConfigCacheService
{
    /**
     * 处理「refresh」相关的内部协作步骤。
     */
    public function refresh(array $env = [], bool $force = false): array
    {
        $cachedConfigPath = $this->cachedConfigPath();
        if (!$force && !is_file($cachedConfigPath)) {
            return [
                'skipped' => true,
                'reason' => 'laravel config cache does not exist',
                'output' => '',
            ];
        }

        $process = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'config:cache',
        ], base_path(), $this->normalizeEnv($env), null, 60);
        $process->run();

        $output = trim($process->getOutput() . PHP_EOL . $process->getErrorOutput());
        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'Laravel config cache refresh failed: ' . ($output !== '' ? $output : 'empty process output')
            );
        }

        return [
            'skipped' => false,
            'reason' => null,
            'output' => $output,
        ];
    }

    /**
     * 处理「refreshIfCached」相关的内部协作步骤。
     */
    public function refreshIfCached(array $env = []): array
    {
        return $this->refresh($env, false);
    }

    /**
     * 处理「cachedConfigPath」相关的内部协作步骤。
     */
    private function cachedConfigPath(): string
    {
        if (method_exists(app(), 'getCachedConfigPath')) {
            return app()->getCachedConfigPath();
        }

        return base_path('bootstrap/cache/config.php');
    }

    /**
     * 处理「normalizeEnv」相关的内部协作步骤。
     */
    private function normalizeEnv(array $env): array
    {
        $normalized = [];
        foreach ($env as $key => $value) {
            $key = strtoupper(trim((string)$key));
            if ($key === '' || preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) !== 1) {
                continue;
            }

            $normalized[$key] = (string)$value;
        }

        return $normalized;
    }
}
