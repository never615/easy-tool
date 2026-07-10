<?php

namespace Mallto\Tool\Domain\LogViewer;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

class LogFileMutationService
{
    /** @var array<string, bool> */
    private array $activeFiles;

    /**
     * @param array<int, string> $activeFiles
     */
    public function __construct(
        private readonly Filesystem $files,
        array $activeFiles
    ) {
        $this->activeFiles = [];

        foreach ($activeFiles as $file) {
            $name = basename(trim($file));
            if ($name !== '' && $name !== '.') {
                $this->activeFiles[$name] = true;
            }
        }
    }

    public function clean(string $path): void
    {
        if ($this->files->put($path, '') === false) {
            throw new RuntimeException("Unable to truncate log file: {$path}");
        }
    }

    public function delete(string $path): void
    {
        if ($this->isActive($path)) {
            $this->clean($path);

            return;
        }

        if ($this->files->exists($path) && !$this->files->delete($path)) {
            throw new RuntimeException("Unable to delete log archive: {$path}");
        }
    }

    /**
     * @param array<int, string> $paths
     */
    public function deleteAll(array $paths): void
    {
        foreach ($paths as $path) {
            $this->delete($path);
        }
    }

    private function isActive(string $path): bool
    {
        return isset($this->activeFiles[basename($path)]);
    }
}
