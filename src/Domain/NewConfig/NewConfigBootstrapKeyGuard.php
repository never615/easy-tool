<?php

namespace Mallto\Tool\Domain\NewConfig;

use Mallto\Tool\Exception\ResourceException;

class NewConfigBootstrapKeyGuard
{
    private const FORBIDDEN_EXACT_KEYS = [
        'DATABASE_URL',
    ];

    private const FORBIDDEN_PREFIXES = [
        'DB_',
        'REDIS_',
        'MONGO_',
        'MONGODB_',
    ];

    public static function normalize(?string $envKey): ?string
    {
        $envKey = strtoupper(trim((string)$envKey));

        return $envKey === '' ? null : $envKey;
    }

    public static function assertAllowed(?string $envKey): void
    {
        $envKey = self::normalize($envKey);
        if ($envKey === null || !self::isForbidden($envKey)) {
            return;
        }

        throw new ResourceException(self::forbiddenMessage($envKey));
    }

    public static function forbiddenHint(): string
    {
        return '禁止创建 DB_*、REDIS_*、MONGO_*、MONGODB_*、DATABASE_URL 等启动前置连接 key；这些配置必须继续通过 .env、docker-compose env_file 或 K8s Secret/ConfigMap 管理。';
    }

    private static function isForbidden(string $envKey): bool
    {
        if (in_array($envKey, self::FORBIDDEN_EXACT_KEYS, true)) {
            return true;
        }

        foreach (self::FORBIDDEN_PREFIXES as $prefix) {
            if (str_starts_with($envKey, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function forbiddenMessage(string $envKey): string
    {
        return "Env Key [{$envKey}] 属于 DB/Redis/Mongo 等启动前置连接配置，配置中心读取数据库后才能工作，不能在配置中心创建或发布。请继续通过 .env、docker-compose env_file 或 K8s Secret/ConfigMap 管理。";
    }
}
