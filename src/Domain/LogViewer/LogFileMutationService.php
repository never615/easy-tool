<?php

namespace Mallto\Tool\Domain\LogViewer;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * LogFileMutationService：easy-tool 的 log file mutation service 组件。
 * 它通过明确的职责边界供包内其他模块复用。
 */
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

    /**
     * 处理「clean」相关的内部协作步骤。
     */
    public function clean(string $path): void
    {
        if ($this->files->put($path, '') === false) {
            throw new RuntimeException("Unable to truncate log file: {$path}");
        }
    }

    /**
     * 处理「delete」相关的内部协作步骤。
     */
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

    /**
     * 处理「isActive」相关的内部协作步骤。
     */
    private function isActive(string $path): bool
    {
        return isset($this->activeFiles[basename($path)]);
    }
}
