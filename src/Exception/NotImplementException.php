<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Exception;

use Exception;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * NotImplementException：用于表达 not implement exception 场景的应用异常。
 * 异常类型留给上层处理器识别，以输出符合调用方契约的错误响应。
 */
class NotImplementException extends HttpException
{

    /**
     * 注入本组件运行所需的协作依赖，保持构造与业务执行解耦。
     */
    public function __construct($message = null, Exception $previous = null, $headers = [], $code = 0)
    {
        parent::__construct(405, $message ?: "方法未实现,拒绝访问", $previous, $headers, $code);
    }

}
