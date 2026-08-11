<?php

namespace Mallto\Tool\Domain\NewConfig;

use InvalidArgumentException;

final class GlobalConfigOverrideSource
{
    public const MANUAL = 'manual';

    public const LEGACY_CONFIGS = 'legacy_configs';

    public const SYSTEM = 'system';

    public const REVIEWED = 'reviewed';

    /** @return list<string> */
    public static function values(): array
    {
        return [
            self::MANUAL,
            self::LEGACY_CONFIGS,
            self::SYSTEM,
            self::REVIEWED,
        ];
    }

    public static function isValid(?string $source): bool
    {
        return $source === null || in_array($source, self::values(), true);
    }

    public static function assertValid(?string $source): void
    {
        if (!self::isValid($source)) {
            throw new InvalidArgumentException('Unsupported global config override source.');
        }
    }
}
