<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Utils;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mallto\Tool\Data\Config;
use Mallto\Tool\Exception\ResourceException;

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
     * 读取配置
     *
     * 读取config表中配置项
     *
     * @param      $key
     * @param null $default
     * @param null $type
     *
     * @return null
     */
    public static function get($key, $default = null, $type = null)
    {
        $value = Cache::store('memory')->get('c_' . $key);
        if (is_null($value)) {
            $query = Config::where("key", $key);
            if ($type) {
                $query = $query->where("type", $type);
            }
            $config = $query->first();
            if ($config) {
                $value = $config->value;
                Cache::store('memory')->put('c_' . $key, $value, Carbon::now()->endOfDay());
            } else {
                if (isset($default)) {
                    $value = $default;
                } else {
                    throw new ResourceException($key . "未配置");
                }
            }
        }

        return $value;
    }


    /**
     * 设置配置
     *
     * @param $key
     * @param $value
     *
     * @return null
     */
    public static function set($key, $value)
    {
        return Config::updateOrCreate([
            "key" => $key,
        ], [
            "value" => $value,
        ]);
    }
}
