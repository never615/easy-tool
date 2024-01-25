<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Exception;

/**
 * 自定义的http异常,一般在需要返回自定义响应码的时候使用
 *
 * @package    App\Exceptions
 */
class HttpException extends \Symfony\Component\HttpKernel\Exception\HttpException
{

    protected $errCode;

    protected $content;


    /**
     * HttpException constructor.
     *
     * @param int             $statusCode http响应码
     * @param null            $message
     * @param int             $errCode    自定义的错误响应码
     * @param array           $content    不能包含error和code的key
     * @param \Exception|null $previous
     * @param array           $headers
     * @param int             $code
     */
    public function __construct(
        $statusCode,
        $message = null,
        $errCode = 0,
        $content = null,
        \Exception $previous = null,
        array $headers = [],
        $code = 0
    ) {
        $this->errCode = $errCode;
        $this->content = $content;
        parent::__construct($statusCode, $message, $previous, $headers, $errCode);
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


    /**
     * @return null
     */
    public function getContent()
    {
        return $this->content;
    }


    /**
     * @param null $content
     */
    public function setContent($content): void
    {
        $this->content = $content;
    }


    public function getResponseContent()
    {
        if ($this->errCode) {
            return array_merge([
                "error" => $this->message,
                "code"  => $this->errCode,
            ], (array) $this->content);
        }

        return array_merge([
            "error" => $this->message,
        ], (array) $this->content);
    }

}
