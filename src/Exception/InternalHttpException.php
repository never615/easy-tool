<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Exception;

use Exception;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Support\Facades\Log;

/**
 * InternalHttpException：用于表达 internal http exception 场景的应用异常。
 * 异常类型留给上层处理器识别，以输出符合调用方契约的错误响应。
 */
class InternalHttpException extends HttpException
{

    /**
     * The response.
     *
     * @var \Illuminate\Http\Response
     */
    protected $response;


    /**
     * Create a new internal HTTP exception instance.
     *
     * @param \Symfony\Component\HttpFoundation\Response $response
     * @param string                                     $message
     * @param \Exception                                 $previous
     * @param array                                      $headers
     * @param int                                        $code
     *
     * @return void
     */
    public function __construct($message = null, Exception $previous = null, array $headers = [], $code = 0)
    {
        Log::error("InternalHttpException:" . $message);

        parent::__construct(500, $message, $previous, $headers, $code);
    }

}
