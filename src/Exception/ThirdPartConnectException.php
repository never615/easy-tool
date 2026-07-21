<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Exception;

use Exception;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 第三方服务连接异常
 * Class ThirdPartException
 *
 * @package App\Exceptions
 */
class ThirdPartConnectException extends HttpException
{

    /**
     * 注入本组件运行所需的协作依赖，保持构造与业务执行解耦。
     */
    public function __construct($message = null, Exception $previous = null, $headers = [], $code = 0)
    {
        parent::__construct(500, $message ?: "第三方服务连接异常", $previous, $headers, $code);
    }
}
