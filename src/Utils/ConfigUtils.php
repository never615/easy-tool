<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Utils;

use Mallto\Tool\Data\NewConfig;
use Mallto\Tool\Domain\NewConfig\GlobalConfigNewConfig;

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
     * @param bool $cacheNullValue 手机哦凑韩村
     * @return null
     */
    public static function get($key, $default = null, $cacheNullValue = true, $ttl = null)
    {
        $values = config('new_config.values', []);
        if (is_array($values) && array_key_exists($key, $values)) {
            return $values[$key];
        }

        return $default ?? ($cacheNullValue ? '' : null);
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
        $attributes = GlobalConfigNewConfig::attributesFor((string)$key, (string)$value);
        $config = NewConfig::query()->firstOrNew([
            'key' => (string)$key,
        ]);

        if (!$config->exists) {
            $config->fill($attributes);
        } else {
            $config->env_key = $attributes['env_key'];
            $config->group_key = $attributes['group_key'];
            $config->type = $attributes['type'];
            $config->is_enabled = $attributes['is_enabled'];
            $config->requires_reload = $attributes['requires_reload'];
        }

        $config->value = $attributes['value'];
        $config->save();

        return $config;
    }
}
