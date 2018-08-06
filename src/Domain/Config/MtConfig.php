<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Config;

use Mallto\Tool\Exception\ResourceException;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2018/8/2
 * Time: 下午6:53
 */
class MtConfig
{

    /**
     * 获取配置
     *
     * @param      $key
     * @param null $default
     * @param null $type
     * @return mixed
     */
    public function getConfig($key, $default = null, $type = null)
    {
        $query = \Mallto\Tool\Data\Config::where("key", $key);
        if ($type) {
            $query = $query->where("type", $type);
        }
        $config = $query->first();
        if ($config) {
            return $config->value;
        } else {
            if ($default) {
                return $default;
            } else {
                throw new ResourceException($key."未配置");
            }
        }
    }

}