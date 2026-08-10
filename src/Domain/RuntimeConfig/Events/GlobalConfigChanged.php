<?php

namespace Mallto\Tool\Domain\RuntimeConfig\Events;

final class GlobalConfigChanged
{
    public function __construct(
        public readonly string $key,
        public readonly ?string $originalKey = null
    ) {
    }
}
