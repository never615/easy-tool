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

/**
 * NeedBindMobileException：用于表达 need bind mobile exception 场景的应用异常。
 * 异常类型留给上层处理器识别，以输出符合调用方契约的错误响应。
 */
class NeedBindMobileException extends HttpException
{

    /**
     * 注入本组件运行所需的协作依赖，保持构造与业务执行解耦。
     */
    public function __construct(
        $message = "需要绑定手机",
        $statusCode = "422"
    ) {
        parent::__construct($statusCode, $message, 4105);
    }
}
