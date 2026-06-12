<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Config;

use Mallto\Tool\Exception\ResourceException;
use Mallto\Tool\Utils\ConfigUtils;

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
     *
     * @return mixed
     */
    public function getConfig($key, $default = null, $type = null)
    {
        $value = ConfigUtils::get($key, null, false);
        if ($value !== null && $value !== '') {
            return $value;
        }

        if ($default) {
            return $default;
        }

        throw new ResourceException($key . "未配置");
    }

}
