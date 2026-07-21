<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Exception;

use Exception;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 签名错误
 * Class ThirdPartException
 *
 * @package App\Exceptions
 */
class SignException extends HttpException
{

    /**
     * 注入本组件运行所需的协作依赖，保持构造与业务执行解耦。
     */
    public function __construct($message = null, Exception $previous = null, $headers = [], $code = 0)
    {
        parent::__construct(401, $message ?: trans("errors.sign_error"), $previous, $headers, $code);
    }
}
