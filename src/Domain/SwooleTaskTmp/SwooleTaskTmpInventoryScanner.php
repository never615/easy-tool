<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\SwooleTaskTmp;

use FilesystemIterator;
use Throwable;
use UnexpectedValueException;

final class SwooleTaskTmpInventoryScanner
{
    private const TASK_FILE_PREFIX = 'swoole.task.';

    private const FILE_TYPE_MASK = 0170000;

    private const DIRECTORY_TYPE = 0040000;

    private const REGULAR_FILE_TYPE = 0100000;

    /**
     * @param null|callable(): bool $shouldContinue
     */
    public function scan(
        string $directory,
        int $cutoffTimestamp,
        ?callable $shouldContinue = null
    ): SwooleTaskTmpInventoryResult {
        $startedAt = hrtime(true);
        $scanned = 0;
        $matchingFiles = 0;
        $matchingBytes = 0;
        $staleFiles = 0;
        $staleBytes = 0;
        $oldestMtime = null;
        $normalizedDirectory = $this->normalizeDirectory($directory);

        if (!$this->isSafeDirectory($normalizedDirectory)) {
            return $this->result(
                $startedAt,
                $scanned,
                $matchingFiles,
                $matchingBytes,
                $staleFiles,
                $staleBytes,
                $oldestMtime,
                false,
                UnexpectedValueException::class
            );
        }

        try {
            $iterator = new FilesystemIterator(
                $normalizedDirectory,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO
            );

            foreach ($iterator as $entry) {
                if ($shouldContinue !== null && !$shouldContinue()) {
                    return $this->result(
                        $startedAt,
                        $scanned,
                        $matchingFiles,
                        $matchingBytes,
                        $staleFiles,
                        $staleBytes,
                        $oldestMtime,
                        false
                    );
                }

                $scanned++;

                if (!str_starts_with($entry->getFilename(), self::TASK_FILE_PREFIX)) {
                    continue;
                }

                $path = $entry->getPathname();
                clearstatcache(true, $path);
                $stat = @lstat($path);

                if ($stat === false
                    || ((int) $stat['mode'] & self::FILE_TYPE_MASK) !== self::REGULAR_FILE_TYPE) {
                    continue;
                }

                $mtime = (int) $stat['mtime'];
                $bytes = (int) $stat['size'];
                $matchingFiles++;
                $matchingBytes += $bytes;
                $oldestMtime = $oldestMtime === null ? $mtime : min($oldestMtime, $mtime);

                if ($mtime <= $cutoffTimestamp) {
                    $staleFiles++;
                    $staleBytes += $bytes;
                }
            }
        } catch (Throwable $exception) {
            return $this->result(
                $startedAt,
                $scanned,
                $matchingFiles,
                $matchingBytes,
                $staleFiles,
                $staleBytes,
                $oldestMtime,
                false,
                $exception::class
            );
        }

        return $this->result(
            $startedAt,
            $scanned,
            $matchingFiles,
            $matchingBytes,
            $staleFiles,
            $staleBytes,
            $oldestMtime,
            true
        );
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

    private function result(
        int $startedAt,
        int $scanned,
        int $matchingFiles,
        int $matchingBytes,
        int $staleFiles,
        int $staleBytes,
        ?int $oldestMtime,
        bool $complete,
        ?string $errorType = null
    ): SwooleTaskTmpInventoryResult {
        return new SwooleTaskTmpInventoryResult(
            $scanned,
            $matchingFiles,
            $matchingBytes,
            $staleFiles,
            $staleBytes,
            $oldestMtime,
            $complete,
            max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000)),
            $errorType
        );
    }
}
