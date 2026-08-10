<?php

namespace Mallto\Tool\Domain\NewConfig;

use Mallto\Tool\Domain\RuntimeConfig\RuntimeConfigCacheMode;

final class GlobalConfigApplyMode
{
    public const SWOOLE_REDIS = RuntimeConfigCacheMode::SWOOLE_REDIS;
    public const SHARED_REDIS = RuntimeConfigCacheMode::SHARED_REDIS;

    public static function normalize(?string $mode): string
    {
        $mode = RuntimeConfigCacheMode::normalize($mode);

        return RuntimeConfigCacheMode::isRuntime($mode) ? $mode : self::SWOOLE_REDIS;
    }

    public static function forKey(string $key): string
    {
        if ($key === 'location_args') {
            return self::SHARED_REDIS;
        }

        $definition = GlobalConfigDefinitions::find($key);

        return self::normalize(is_array($definition) ? ($definition['apply_mode'] ?? null) : null);
    }

    public static function requiresReload(string $key): bool
    {
        return false;
    }

    public static function label(?string $mode): string
    {
        return self::normalize($mode) === self::SHARED_REDIS
            ? '共享 Redis，保存后生效'
            : 'Swoole / 共享 Redis，保存后生效';
    }
}
