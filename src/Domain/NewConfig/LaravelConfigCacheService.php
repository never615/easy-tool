<?php

namespace Mallto\Tool\Domain\NewConfig;

use RuntimeException;
use Symfony\Component\Process\Process;

class LaravelConfigCacheService
{
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

    public function refreshIfCached(array $env = []): array
    {
        return $this->refresh($env, false);
    }

    private function cachedConfigPath(): string
    {
        if (method_exists(app(), 'getCachedConfigPath')) {
            return app()->getCachedConfigPath();
        }

        return base_path('bootstrap/cache/config.php');
    }

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
