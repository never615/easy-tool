<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Controller\Api;

use Illuminate\Routing\Controller;

/**
 * 由 PhpStorm 创建。
 * 作者： never615
 * 日期： 20/04/2017
 * 时间： 11:45 上午
 */
class TimeController extends Controller
{

    /**
     * 获取当前系统时间
     */
    public function now()
    {
        return time();
    }

}
