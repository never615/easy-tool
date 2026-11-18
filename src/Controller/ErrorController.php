<?php
/**
 * 版权所有 (c) 2019. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Controller;

use Illuminate\Http\Request;

/**
 * 由 PhpStorm 创建。
 * 作者： never615 <never615.com>
 * 日期： 2019/2/13
 * 时间： 11:32 上午
 */
class ErrorController
{

    /**
     * 处理「index」相关的内部协作步骤。
     */
    public function index($code, Request $request)
    {
        return view("tooL_errors::$code");
    }
}
