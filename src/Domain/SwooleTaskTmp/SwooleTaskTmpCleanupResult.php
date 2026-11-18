<?php

/*
 * 版权所有 (c) 2026. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Domain\SwooleTaskTmp;

final class SwooleTaskTmpCleanupResult
{
    public function __construct(
        public readonly int $scanned,
        public readonly int $deleted,
        public readonly int $failed,
        public readonly bool $limitReached,
        public readonly bool $interrupted,
        public readonly int $durationMilliseconds,
        public readonly ?string $errorType = null,
    ) {
    }
}
