<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Exception;

use Exception;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @package App\Exceptions
 */
class InvalidParamException extends HttpException
{

    public function __construct($message = null, Exception $previous = null, $headers = [], $code = 0)
    {
        parent::__construct(400, $message ?: trans("errors.invalided_param"), $previous, $headers, $code);
    }
}
