<?php
/**
 * 版权所有 (c) 2018. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Domain\Config;

/**
 * 由 PhpStorm 创建。
 * 作者： never615 <never615.com>
 * 日期： 2018/8/2
 * 时间： 下午6:53
 */
interface Config
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
    public function getConfig($key, $default = null, $type = null);
}
