<?php

namespace Mallto\Tool\Domain\Exception;



use Exception;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 第三方服务异常
 * Class ThirdPartException
 * @package App\Exceptions
 */
class ThirdPartException extends HttpException
{
    
    public function __construct($message = null, Exception $previous = null, $headers = [], $code = 0)
    {
        parent::__construct(503, $message ?: trans("errors.third_part_error"), $previous, $headers, $code);
    }
}
