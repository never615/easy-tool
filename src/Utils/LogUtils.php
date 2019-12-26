<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Utils;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2018/11/16
 * Time: 2:32 PM
 */
class LogUtils
{

    /**
     * 系统参数未配置
     *
     * 需要由项目拥有者进行配置
     *
     * @param      $msg
     */
    public static function notConfigLogByOwner($msg)
    {
        \Log::warning("owner_config:" . $msg);
    }


    /**
     * 系统参数未配置
     *
     * 需要由subject管理者配置
     *
     * @param $msg
     */
    public static function notConfigLogBySubjecter($msg)
    {
        \Log::warning("subjecter_config:" . $msg);
    }

}
