<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Utils;

use Mallto\Tool\Domain\NewConfig\GlobalConfigOverrideManager;
use Mallto\Tool\Domain\NewConfig\GlobalConfigRuntimeResolver;

/**
 *
 * 读取Model Config中的配置
 *
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2019/3/14
 * Time: 4:54 PM
 */
class ConfigUtils
{

    /**
     * 处理「getJson2Array」相关的内部协作步骤。
     */
    public static function getJson2Array($key, $default = [])
    {
        $result = self::get($key, $default);
        if (is_null($result)) {
            return $result;
        } else {
            if (is_string($result)) {
                $result = json_decode($result, true);
            }

            return array_merge($default, $result);
        }
    }


    /**
     * 读取配置
     *
     * 读取config表中配置项
     *
     * @param      $key
     * @param null $default
     * @param bool $cacheNullValue 手机哦凑韩村
     * @return null
     */
    public static function get($key, $default = null, $cacheNullValue = true, $ttl = null)
    {
        return app(GlobalConfigRuntimeResolver::class)->get(
            (string)$key,
            $default,
            (bool)$cacheNullValue
        );
    }

    /**
     * 强制从 PostgreSQL 刷新当前业务配置，并回填共享缓存与可用的 Swoole L1。
     */
    public static function refresh($key, $default = null, $cacheNullValue = true)
    {
        return app(GlobalConfigRuntimeResolver::class)->fresh(
            (string)$key,
            $default,
            (bool)$cacheNullValue
        );
    }

    /** Batch independent runtime keys without adding a process-local cache window. */
    public static function getMany(array $defaults, bool $cacheNullValue = true): array
    {
        return app(GlobalConfigRuntimeResolver::class)->getMany($defaults, $cacheNullValue);
    }


    /**
     * 设置配置
     *
     * @param $key
     * @param $value
     * @param null $ttl
     * @return null
     */
    public static function set($key, $value, $ttl = null)
    {
        return app(GlobalConfigOverrideManager::class)->setSystem((string)$key, $value);
    }
}
