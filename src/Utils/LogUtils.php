<?php
/**
 * 版权所有 (c) 2018. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Utils;

use Illuminate\Support\Facades\Log;

/**
 * 由 PhpStorm 创建。
 * 作者： never615 <never615.com>
 * 日期： 2018/11/16
 * 时间： 2:32 下午
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
        Log::warning("owner_config:" . $msg);
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
        Log::warning("subjecter_config:" . $msg);
    }

}
