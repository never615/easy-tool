<?php

namespace Mallto\Tool\Domain\RuntimeConfig;

final class RuntimeConfigCacheMode
{
    public const SWOOLE_REDIS = 'swoole_redis';
    public const SHARED_REDIS = 'shared_redis';
    public const BOOTSTRAP_RELOAD = 'bootstrap_reload';

    public static function normalize(?string $mode, string $default = self::SWOOLE_REDIS): string
    {
        $allowed = [self::SWOOLE_REDIS, self::SHARED_REDIS, self::BOOTSTRAP_RELOAD];
        $normalizedDefault = in_array($default, $allowed, true) ? $default : self::SWOOLE_REDIS;

        return in_array($mode, $allowed, true) ? $mode : $normalizedDefault;
    }

    public static function usesSwoole(string $mode): bool
    {
        return $mode === self::SWOOLE_REDIS;
    }

    public static function isRuntime(string $mode): bool
    {
        return $mode === self::SWOOLE_REDIS || $mode === self::SHARED_REDIS;
    }
}
