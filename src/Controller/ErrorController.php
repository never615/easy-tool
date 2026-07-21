<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller;

use Illuminate\Http\Request;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2019/2/13
 * Time: 11:32 AM
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
