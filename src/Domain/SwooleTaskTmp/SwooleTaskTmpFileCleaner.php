<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\SwooleTaskTmp;

use FilesystemIterator;
use Throwable;
use UnexpectedValueException;

final class SwooleTaskTmpFileCleaner
{
    private const TASK_FILE_PREFIX = 'swoole.task.';

    private const FILE_TYPE_MASK = 0170000;

    private const REGULAR_FILE_TYPE = 0100000;

    /**
     * @param null|callable(): bool $shouldContinue
     */
    public function clean(
        string $directory,
        int $cutoffTimestamp,
        int $deleteLimit,
        ?callable $shouldContinue = null
    ): SwooleTaskTmpCleanupResult {
        $startedAt = hrtime(true);
        $scanned = 0;
        $deleted = 0;
        $failed = 0;
        $deleteLimit = max(1, $deleteLimit);

        if (!str_starts_with($directory, '/') || !is_dir($directory) || !is_readable($directory)) {
            return $this->result(
                $startedAt,
                $scanned,
                $deleted,
                1,
                false,
                false,
                UnexpectedValueException::class
            );
        }

        try {
            $iterator = new FilesystemIterator(
                $directory,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO
            );

            foreach ($iterator as $entry) {
                if ($shouldContinue !== null && !$shouldContinue()) {
                    return $this->result(
                        $startedAt,
                        $scanned,
                        $deleted,
                        $failed,
                        false,
                        true
                    );
                }

                $scanned++;

                if (!str_starts_with($entry->getFilename(), self::TASK_FILE_PREFIX)) {
                    continue;
                }

                $path = $entry->getPathname();
                $stat = @lstat($path);

                if ($stat === false) {
                    $failed++;
                    continue;
                }

                if (((int) $stat['mode'] & self::FILE_TYPE_MASK) !== self::REGULAR_FILE_TYPE) {
                    continue;
                }

                if ((int) $stat['mtime'] > $cutoffTimestamp) {
                    continue;
                }

                if (!@unlink($path)) {
                    $failed++;
                    continue;
                }

                $deleted++;

                if ($deleted >= $deleteLimit) {
                    return $this->result(
                        $startedAt,
                        $scanned,
                        $deleted,
                        $failed,
                        true,
                        false
                    );
                }
            }
        } catch (Throwable $exception) {
            return $this->result(
                $startedAt,
                $scanned,
                $deleted,
                $failed + 1,
                false,
                false,
                $exception::class
            );
        }

        return $this->result($startedAt, $scanned, $deleted, $failed, false, false);
    }

    private function result(
        int $startedAt,
        int $scanned,
        int $deleted,
        int $failed,
        bool $limitReached,
        bool $interrupted,
        ?string $errorType = null
    ): SwooleTaskTmpCleanupResult {
        return new SwooleTaskTmpCleanupResult(
            $scanned,
            $deleted,
            $failed,
            $limitReached,
            $interrupted,
            max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000)),
            $errorType
        );
    }
}
