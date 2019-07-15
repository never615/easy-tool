<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

/**
 * Created by PhpStorm.
 * User: never615
 * Date: 12/07/2017
 * Time: 3:03 PM
 */

namespace Mallto\Tool\Exception;




class NeedBindMobileException extends HttpException
{
    public function __construct(
        $message = "需要绑定手机",
        $statusCode = "422"
    ) {
        parent::__construct($statusCode, $message,4105);
    }
}
