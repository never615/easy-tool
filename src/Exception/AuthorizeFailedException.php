<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Exception;


use Exception;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 授权失败
 * Class ThirdPartException
 *
 * @package App\Exceptions
 */
class AuthorizeFailedException extends HttpException
{

    public function __construct($message = null, Exception $previous = null, $headers = [], $code = 0)
    {
        parent::__construct(401, $message ?: "授权失败", $previous, $headers, $code);
    }
}
