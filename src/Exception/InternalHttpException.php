<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
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
     * 响应。
     *
     * @var \Illuminate\Http\Response
     */
    protected $response;


    /**
     * 创建内部 HTTP 异常实例。
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
