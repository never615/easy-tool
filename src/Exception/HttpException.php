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

    protected $content;

    /**
     * HttpException constructor.
     *
     * @param                 $statusCode
     * @param null            $message
     * @param null            $errCode
     * @param array           $content 不能包含error和code的key
     * @param \Exception|null $previous
     * @param array           $headers
     * @param int             $code
     */
    public function __construct(
        $statusCode,
        $message = null,
        $errCode = null,
        $content = null,
        \Exception $previous = null,
        array $headers = array (),
        $code = 0 //todo 系统有定义异常码,我又自己定义了errCode,优化
    )
    {
        $this->errCode = $errCode;
        $this->content = $content;
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
        return array_merge([
            "error" => $this->message,
            "code"  => $this->errCode,
        ], (array) $this->content);
    }


}
