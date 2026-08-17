<?php

namespace Mallto\Tool\Domain\MongoDb;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Mallto\Tool\Utils\ConfigUtils;
use Throwable;

/**
 * MongoDB 业务读写的统一动态门禁。
 *
 * 环境配置只提供默认值；配置中心覆盖通过现有 Swoole/Redis 运行时缓存传播，调用方每次
 * 准备访问 MongoDB 前检查，确保已经排队的 Job 也能在维护模式下安全短路。
 */
class MongoDbAccessPolicy
{
    public const CONFIG_KEY = 'database.mongodb.enabled';

    /** @var callable(string, mixed): mixed */
    private $runtimeValueResolver;

    public function __construct(
        private readonly ConfigRepository $config,
        ?callable $runtimeValueResolver = null
    ) {
        $this->runtimeValueResolver = $runtimeValueResolver
            ?? static fn (string $key, mixed $fallback): mixed => ConfigUtils::get($key, $fallback);
    }

    /** 返回当前 MongoDB 业务读写是否允许。 */
    public function enabled(): bool
    {
        $baseline = filter_var(
            $this->config->get(self::CONFIG_KEY, true),
            FILTER_VALIDATE_BOOLEAN
        );

        try {
            return filter_var(
                ($this->runtimeValueResolver)(self::CONFIG_KEY, $baseline),
                FILTER_VALIDATE_BOOLEAN
            );
        } catch (Throwable) {
            // Fresh installs may evaluate providers before the global-config table exists.
            // Connection/bootstrap availability remains owned by the environment baseline.
            return $baseline;
        }
    }

    /** MongoDB 读取关闭时抛出稳定的 HTTP 503 维护异常。 */
    public function ensureReadable(): void
    {
        if (! $this->enabled()) {
            throw new MongoDbMaintenanceException();
        }
    }
}
