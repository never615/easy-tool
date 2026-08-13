<?php

namespace Mallto\Tool\Domain\NewConfig;

use Illuminate\Support\Facades\Cache;

/**
 * NewConfigManualPublishService：集中处理管理员主动发布启动配置的节流与发布边界。
 */
final class NewConfigManualPublishService
{
    public const THROTTLE_KEY = 'new_configs:manual_reload_throttle';

    public const THROTTLE_SECONDS = 30;

    /**
     * 注入发布器，供配置中心和受控调试开关复用同一个人工发布路径。
     */
    public function __construct(private NewConfigPublisher $publisher)
    {
    }

    /**
     * 在取得人工发布限流令牌后，先执行受控写入，再发布启动配置并请求重启。
     * 限流期间不会执行受控写入，并返回 null 由调用方选择对应的 HTTP 或页面提示。
     */
    public function publish(?callable $beforePublish = null): ?array
    {
        if (!Cache::add(self::THROTTLE_KEY, time(), self::THROTTLE_SECONDS)) {
            return null;
        }

        if ($beforePublish !== null) {
            $beforePublish();
        }

        return $this->publisher->publish(true, true);
    }
}
