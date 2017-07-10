<?php

namespace Mallto\Tool\Exception;


use Exception;
use Symfony\Component\HttpKernel\Exception\HttpException;

class NotImplementException extends HttpException
{

    public function __construct($message = null, Exception $previous = null, $headers = [], $code = 0)
    {
        parent::__construct(405, $message ?: "方法未实现,拒绝访问", $previous, $headers, $code);
    }

}
