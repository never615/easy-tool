<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Exception;


/**
 * 自定义的http异常,一般在需要返回自定义响应码的时候使用
 *
 * @package App\Exceptions
 */
class HttpException extends \Symfony\Component\HttpKernel\Exception\HttpException
{
    protected $errCode;

    public function __construct(
        $statusCode,
        $message = null,
        $errCode = null,
        \Exception $previous = null,
        array $headers = array (),
        $code = 0
    ) {
        $this->errCode = $errCode;
        parent::__construct($statusCode, $message, $previous, $headers, $code);
    }

    /**
     * @return null
     */
    public function getErrCode()
    {

        return $this->errCode ?: $this->getStatusCode();
    }

    /**
     * @param null $errCode
     */
    public function setErrCode($errCode)
    {
        $this->errCode = $errCode;
    }


}
