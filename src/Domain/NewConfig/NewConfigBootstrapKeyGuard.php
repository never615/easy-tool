<?php

namespace Mallto\Tool\Domain\NewConfig;

use Mallto\Tool\Exception\ResourceException;

/**
 * NewConfigBootstrapKeyGuard：配置中心发布与运行时生效链路的一环。
 * 它区分持久化配置、生成文件、配置缓存与长驻进程重载，避免调用方绕过发布边界。
 */
class NewConfigBootstrapKeyGuard
{
    private const GENERATED_GLOBAL_CONFIG_PREFIX = 'GLOBAL_CONFIG_';

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

    /**
     * 处理「normalize」相关的内部协作步骤。
     */
    public static function normalize(?string $envKey): ?string
    {
        $envKey = strtoupper(trim((string)$envKey));

        return $envKey === '' ? null : $envKey;
    }

    /**
     * 处理「assertAllowed」相关的内部协作步骤。
     */
    public static function assertAllowed(?string $envKey): void
    {
        $envKey = self::normalize($envKey);
        if ($envKey === null || !self::isForbidden($envKey, true)) {
            return;
        }

        throw new ResourceException(self::forbiddenMessage($envKey));
    }

    /**
     * 处理「assertAllowedForRuntimeOverride」相关的内部协作步骤。
     */
    public static function assertAllowedForRuntimeOverride(?string $envKey): void
    {
        $envKey = self::normalize($envKey);
        if ($envKey === null || !self::isForbiddenForRuntimeOverride($envKey)) {
            return;
        }

        throw new ResourceException(self::forbiddenMessage($envKey));
    }

    /**
     * 处理「isForbiddenForRuntimeOverride」相关的内部协作步骤。
     */
    public static function isForbiddenForRuntimeOverride(?string $envKey): bool
    {
        $envKey = self::normalize($envKey);
        if ($envKey === null) {
            return false;
        }

        return self::isForbidden($envKey, false);
    }

    /**
     * 处理「isForbidden」相关的内部协作步骤。
     */
    private static function isForbidden(string $envKey, bool $allowGeneratedGlobalConfigKeys): bool
    {
        $envKey = self::normalize($envKey);
        if ($envKey === null) {
            return false;
        }

        if (str_starts_with($envKey, self::GENERATED_GLOBAL_CONFIG_PREFIX)) {
            return !$allowGeneratedGlobalConfigKeys;
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

    /**
     * 处理「forbiddenHint」相关的内部协作步骤。
     */
    public static function forbiddenHint(): string
    {
        return '禁止创建 APP_*、DB_*、REDIS_*、LOCAL_REDIS_*、MONGO_*、MONGODB_*、CACHE_*、SESSION_*、MAIL_*、QINIU_*、ALIYUN_*、WECHAT_*、MQTT_*、NEW_CONFIG_*、SUBJECT_CONFIG_*、LARAVELS_*、HORIZON_*、GLOBAL_CONFIG_* 等启动、连接、密钥、配置中心自身或内部生成 key；包含 PASSWORD、SECRET、TOKEN、ACCESS_KEY、PRIVATE_KEY、PASSPHRASE、CERT、KEY_PATH 的 key 也禁止配置。';
    }

    /**
     * 处理「forbiddenMessage」相关的内部协作步骤。
     */
    private static function forbiddenMessage(string $envKey): string
    {
        return "Env Key [{$envKey}] 属于启动、连接、密钥或配置中心自身配置，不能在配置中心创建或发布。请继续通过 .env、docker-compose env_file 或 K8s Secret/ConfigMap 管理。";
    }
}
