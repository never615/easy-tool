<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Utils;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mallto\Tool\Data\Config;
use Psr\SimpleCache\InvalidArgumentException;

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
     * @param null $ttl
     * @param bool $cacheNullValue 手机哦凑韩村
     * @return null
     * @throws InvalidArgumentException
     */
    public static function get($key, $default = null, $cacheNullValue = true, $ttl = null)
    {
        $value = Cache::store('local_redis')->get('c_' . $key);
        if ($value === null) {
            $query = Config::where('key', $key);
            //if ($type) {
            //    $query = $query->where("type", $type);
            //}
            $config = $query->first();
            if ($config) {
                $value = $config->value;
            } else {
                if (isset($default)) {
                    $value = $default;
                } else if ($cacheNullValue) {
                    $value = '';
                }
            }

            if (!$ttl) {
                $ttl = Carbon::now()->endOfDay();
            }

            if ($value !== null) {
                Cache::store('local_redis')->put('c_' . $key, $value, $ttl);
            }
        }

        return $value ?? $default ?? null;
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
        if (!$ttl) {
            $ttl = Carbon::now()->endOfDay();
        }
        Cache::store('local_redis')->put('c_' . $key, $value, $ttl);
        return Config::updateOrCreate([
                "key" => $key,
        ], [
                "value" => $value,
        ]);
    }
}
