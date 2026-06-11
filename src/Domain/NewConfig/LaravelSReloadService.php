<?php

namespace Mallto\Tool\Domain\NewConfig;

use RuntimeException;
use Symfony\Component\Process\Process;

class LaravelSReloadService
{
    public function reload(): array
    {
        $pidFile = storage_path('laravels.pid');
        if (!is_file($pidFile)) {
            return [
                'skipped' => true,
                'reason' => 'laravels pid file does not exist',
                'output' => '',
            ];
        }

        $process = new Process([
            PHP_BINARY,
            base_path('bin/laravels'),
            'reload',
        ], base_path(), null, null, 30);
        $process->run();

        $output = trim($process->getOutput() . PHP_EOL . $process->getErrorOutput());
        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'LaravelS reload failed: ' . ($output !== '' ? $output : 'empty process output')
            );
        }

        return [
            'skipped' => false,
            'reason' => null,
            'output' => $output,
        ];
    }
}
