<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Exception;

use Exception;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 第三方服务异常
 * Class ThirdPartException
 *
 * @package App\Exceptions
 */
class ThirdPartException extends HttpException
{

    /**
     * 注入本组件运行所需的协作依赖，保持构造与业务执行解耦。
     */
    public function __construct($message = null, Exception $previous = null, $headers = [], $code = 0)
    {
        parent::__construct(422, $message ?: trans("errors.third_part_error"), $previous, $headers, $code);
    }
}
