<?php

namespace Mallto\Tool\Domain\Exception;



use Exception;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 *
 * Class ThirdPartException
 * @package App\Exceptions
 */
class NotFoundException extends HttpException
{

    public function __construct($message = null, Exception $previous = null, $headers = [], $code = 0)
    {
        parent::__construct(404, $message ?: trans("errors.not_found"), $previous, $headers, $code);
    }
}
