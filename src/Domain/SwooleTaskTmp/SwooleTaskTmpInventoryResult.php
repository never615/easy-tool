<?php

/*
 * 版权所有 (c) 2026. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Domain\SwooleTaskTmp;

final readonly class SwooleTaskTmpInventoryResult
{
    public function __construct(
        public int $scanned,
        public int $matchingFiles,
        public int $matchingBytes,
        public int $staleFiles,
        public int $staleBytes,
        public ?int $oldestMtime,
        public bool $complete,
        public int $durationMilliseconds,
        public ?string $errorType = null
    ) {
    }

    /**
     * @return array{
     *     scanned: int,
     *     matchingFiles: int,
     *     matchingBytes: int,
     *     staleFiles: int,
     *     staleBytes: int,
     *     oldestMtime: ?int,
     *     complete: bool,
     *     durationMilliseconds: int,
     *     errorType: ?string
     * }
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
