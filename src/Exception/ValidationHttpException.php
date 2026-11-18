<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Exception;

use Exception;

/**
 * ValidationHttpException：用于表达 validation http exception 场景的应用异常。
 * 异常类型留给上层处理器识别，以输出符合调用方契约的错误响应。
 */
class ValidationHttpException extends ResourceException
{

    /**
     * 创建 HTTP 校验异常实例。
     *
     * @param \Illuminate\Support\MessageBag|array $errors
     * @param \Exception                           $previous
     * @param array                                $headers
     * @param int                                  $code
     *
     * @return void
     */
    public function __construct($errors = null, Exception $previous = null, $headers = [], $code = 0)
    {


        parent::__construct(null, $errors, $previous, $headers, $code);
    }
}
