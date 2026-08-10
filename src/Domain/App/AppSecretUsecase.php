<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\App;

use Illuminate\Support\Facades\Cache;
use Mallto\Tool\Data\Config;
use Mallto\Tool\Data\NewConfig;
use Mallto\Tool\Domain\Net\AbstractAPI;
use Mallto\Tool\Exception\ThirdPartException;
use Mallto\Tool\Utils\ConfigUtils;

/**
 *
 * 请求微信开放平台,更新当前应用的秘钥
 *
 * 秘钥用做请求定位接口,如果没有按期更新秘钥,则会无权限请求相应的接口
 *
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2019/3/14
 * Time: 5:07 PM
 */
class AppSecretUsecase extends AbstractAPI
{

    protected $slug = "open_platform";


    /**
     * 处理「update」相关的内部协作步骤。
     */
    public function update()
    {
        $salt = "phfOtwKclusrHKwfgPtfIah1uT3xi";
        $date = now()->format('Ymd');
        $secret = md5(md5($salt . $date . $salt));
        $store = Cache::store((string)config('runtime_config.shared_store', 'redis'));

        return $store->lock('app_secret:rotate:' . $date, 30)->block(5, function () use ($secret): bool {
            $persisted = NewConfig::query()
                ->where('key', Config::APP_SECRET)
                ->first();
            $effectiveValue = $persisted?->value;
            if ($persisted !== null && ($effectiveValue === null || $effectiveValue === '')) {
                $effectiveValue = $persisted->default_value;
            }
            $metadataHealthy = $persisted !== null
                && $persisted->group_key === NewConfig::GROUP_GLOBAL_CONFIG
                && $persisted->is_enabled
                && $persisted->type === 'string'
                && !$persisted->requires_reload;
            if ($metadataHealthy && $effectiveValue !== null && hash_equals((string)$effectiveValue, $secret)) {
                return false;
            }

            ConfigUtils::set(Config::APP_SECRET, $secret);

            return true;
        });
    }


    /**
     * 不同的实现需要重写此方法 标准的json请求使用
     * Check the array data errors, and Throw exception when the contents contains error.
     *
     * @param array $contents
     *
     * @return array
     * @throws ThirdPartException
     */
    protected function checkAndThrow(
        array $contents
    ) {
        // TODO: Implement checkAndThrow() method.
    }
}
