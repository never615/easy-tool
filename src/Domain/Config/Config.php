<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Config;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2018/8/2
 * Time: 下午6:53
 */
interface Config
{

    /**
     * 获取配置
     *
     * @param      $key
     * @param null $default
     * @param null $type
     * @return mixed
     */
    public function getConfig($key, $default = null, $type = null);
}