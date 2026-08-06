<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
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
