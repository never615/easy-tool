<?php

namespace Mallto\Tool\Domain\NewConfig;

use RuntimeException;
use Symfony\Component\Process\Process;

class LaravelConfigCacheService
{
    public function refreshIfCached(): array
    {
        $cachedConfigPath = $this->cachedConfigPath();
        if (!is_file($cachedConfigPath)) {
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
        ], base_path(), null, null, 60);
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

    private function cachedConfigPath(): string
    {
        if (method_exists(app(), 'getCachedConfigPath')) {
            return app()->getCachedConfigPath();
        }

        return base_path('bootstrap/cache/config.php');
    }
}
