<?php

namespace Mallto\Tool\Domain\NewConfig;

use Mallto\Tool\Exception\ResourceException;

class NewConfigBootstrapKeyGuard
{
    private const FORBIDDEN_EXACT_KEYS = [
        'DATABASE_URL',
        'DB_URL',
        'APP_NAME',
        'APP_UNIQUE',
        'APP_ENV',
        'APP_KEY',
        'UPLOAD_PATH',
        'FILE_SYSTEM',
        'BROADCAST_DRIVER',
        'CACHE_DRIVER',
        'SESSION_DRIVER',
        'QUEUE_DRIVER',
        'CACHE_REDIS_CONNECTION',
        'LOCAL_REDIS_DATABASE',
        'ADMIN_AUTH_MODEL',
        'AUTH_USERS_MODEL',
        'SUBJECT',
        'TAGS_MODEL',
        'MALLTO_APP_ID',
        'MALLTO_APP_SECRET',
    ];

    private const FORBIDDEN_PREFIXES = [
        'APP_',
        'DB_',
        'DATABASE_',
        'REMOTE_DB_',
        'TEST_DB_',
        'REDIS_',
        'LOCAL_REDIS_',
        'MONGO_',
        'MONGODB_',
        'MEMCACHED_',
        'CACHE_',
        'SESSION_',
        'MAIL_',
        'PUSHER_',
        'QINIU_',
        'ALIYUN_',
        'AWS_',
        'SES_',
        'MAILGUN_',
        'SPARKPOST_',
        'STRIPE_',
        'WECHAT_',
        'MQTT_',
        'HENGJIYUN_MQTT_',
        'YLWL_MQTT_',
        'NEW_CONFIG_',
        'SUBJECT_CONFIG_',
        'LARAVELS_',
        'HORIZON_',
        'TELESCOPE_',
        'SANCTUM_',
        'ELASTICSEARCH_',
    ];

    private const FORBIDDEN_CONTAINS = [
        'PASSWORD',
        'SECRET',
        'TOKEN',
        'ACCESS_KEY',
        'SECRET_KEY',
        'PRIVATE_KEY',
        'PASSPHRASE',
        'CERT',
        'CERT_PATH',
        'KEY_PATH',
    ];

    public static function normalize(?string $envKey): ?string
    {
        $envKey = strtoupper(trim((string)$envKey));

        return $envKey === '' ? null : $envKey;
    }

    public static function assertAllowed(?string $envKey): void
    {
        $envKey = self::normalize($envKey);
        if ($envKey === null || !self::isForbiddenForRuntimeOverride($envKey)) {
            return;
        }

        throw new ResourceException(self::forbiddenMessage($envKey));
    }

    public static function isForbiddenForRuntimeOverride(?string $envKey): bool
    {
        $envKey = self::normalize($envKey);
        if ($envKey === null) {
            return false;
        }

        if (in_array($envKey, self::FORBIDDEN_EXACT_KEYS, true)) {
            return true;
        }

        foreach (self::FORBIDDEN_PREFIXES as $prefix) {
            if (str_starts_with($envKey, $prefix)) {
                return true;
            }
        }

        foreach (self::FORBIDDEN_CONTAINS as $needle) {
            if (str_contains($envKey, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function forbiddenHint(): string
    {
        return '禁止创建 APP_*、DB_*、REDIS_*、LOCAL_REDIS_*、MONGO_*、MONGODB_*、CACHE_*、SESSION_*、MAIL_*、QINIU_*、ALIYUN_*、WECHAT_*、MQTT_*、NEW_CONFIG_*、SUBJECT_CONFIG_*、LARAVELS_*、HORIZON_* 等启动、连接、密钥或配置中心自身 key；包含 PASSWORD、SECRET、TOKEN、ACCESS_KEY、PRIVATE_KEY、PASSPHRASE、CERT、KEY_PATH 的 key 也禁止配置。';
    }

    private static function forbiddenMessage(string $envKey): string
    {
        return "Env Key [{$envKey}] 属于启动、连接、密钥或配置中心自身配置，不能在配置中心创建或发布。请继续通过 .env、docker-compose env_file 或 K8s Secret/ConfigMap 管理。";
    }
}
