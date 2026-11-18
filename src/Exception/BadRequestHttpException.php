<?php
/**
 * 版权所有 (c) 2019. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Exception;

/**
 * 由 PhpStorm 创建。
 * 作者： never615 <never615.com>
 * 日期： 2019/3/26
 * 时间： 3:19 下午
 */
class BadRequestHttpException extends \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
{

    /**
     * @param string     $message  内部异常消息
     * @param \Exception $previous 前一个异常
     * @param int        $code     内部异常码
     * @param array      $headers
     */
    public function __construct(
        string $message = "请求数据错误",
        \Exception $previous = null,
        int $code = 0,
        array $headers = []
    ) {
        parent::__construct($message, $previous, $headers, $code);
    }
}
