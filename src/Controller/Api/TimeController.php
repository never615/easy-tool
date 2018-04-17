<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Api;


use Illuminate\Routing\Controller;

/**
 * Created by PhpStorm.
 * User: never615
 * Date: 20/04/2017
 * Time: 11:45 AM
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
