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

    public function index($code, Request $request)
    {
        return view('errors.'.$code);
    }
}