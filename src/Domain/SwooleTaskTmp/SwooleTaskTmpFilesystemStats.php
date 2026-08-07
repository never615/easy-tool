<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\SwooleTaskTmp;

use RuntimeException;
use Throwable;
use UnexpectedValueException;

final class SwooleTaskTmpFilesystemStats
{
    private const FILE_TYPE_MASK = 0170000;

    private const DIRECTORY_TYPE = 0040000;

    /**
     * @return array{ok: bool, total_bytes: ?int, free_bytes: ?int, used_bytes: ?int, used_percent: ?float, error_type: ?string}
     */
    public function read(string $directory): array
    {
        $normalizedDirectory = $this->normalizeDirectory($directory);

        if (!$this->isSafeDirectory($normalizedDirectory)) {
            return $this->error(UnexpectedValueException::class);
        }

        try {
            clearstatcache(true, $normalizedDirectory);
            $totalBytes = @disk_total_space($normalizedDirectory);
            $freeBytes = @disk_free_space($normalizedDirectory);

            if ($totalBytes === false || $freeBytes === false) {
                return $this->error(RuntimeException::class);
            }

            $totalBytes = (int) $totalBytes;
            $freeBytes = (int) $freeBytes;
            $usedBytes = max(0, $totalBytes - $freeBytes);

            return [
                'ok' => true,
                'total_bytes' => $totalBytes,
                'free_bytes' => $freeBytes,
                'used_bytes' => $usedBytes,
                'used_percent' => $totalBytes > 0 ? round(($usedBytes / $totalBytes) * 100, 2) : null,
                'error_type' => null,
            ];
        } catch (Throwable) {
            return $this->error(RuntimeException::class);
        }
    }

    private function normalizeDirectory(string $directory): string
    {
        $normalizedDirectory = rtrim($directory, DIRECTORY_SEPARATOR);

        return $normalizedDirectory === '' ? DIRECTORY_SEPARATOR : $normalizedDirectory;
    }

    private function isSafeDirectory(string $directory): bool
    {
        clearstatcache(true, $directory);
        $directoryStat = @lstat($directory);

        return str_starts_with($directory, '/')
            && $directoryStat !== false
            && ((int) $directoryStat['mode'] & self::FILE_TYPE_MASK) === self::DIRECTORY_TYPE
            && is_readable($directory);
    }

    /**
     * @return array{ok: false, total_bytes: null, free_bytes: null, used_bytes: null, used_percent: null, error_type: class-string<Throwable>}
     */
    private function error(string $errorType): array
    {
        return [
            'ok' => false,
            'total_bytes' => null,
            'free_bytes' => null,
            'used_bytes' => null,
            'used_percent' => null,
            'error_type' => $errorType,
        ];
    }
}
