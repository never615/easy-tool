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
class SessionExpiredException extends HttpException
{

    public function __construct($message = null, Exception $previous = null, $headers = [], $code = 0)
    {
        parent::__construct(403, $message ?: trans("errors.session_expired"), $previous, $headers, $code);
    }
}
