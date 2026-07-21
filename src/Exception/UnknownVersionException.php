<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Exception;

use Exception;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * UnknownVersionException：用于表达 unknown version exception 场景的应用异常。
 * 异常类型留给上层处理器识别，以输出符合调用方契约的错误响应。
 */
class UnknownVersionException extends HttpException
{

    /**
     * Create a new unknown version exception instance.
     *
     * @param string     $message
     * @param \Exception $previous
     * @param int        $code
     *
     * @return void
     */
    public function __construct($message = null, Exception $previous = null, $code = 0)
    {
        parent::__construct(400, $message ?: 'The version given was unknown or has no registered routes.',
            $previous, [], $code);
    }
}
