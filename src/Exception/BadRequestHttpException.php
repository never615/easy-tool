<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Exception;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2019/3/26
 * Time: 3:19 PM
 */
class BadRequestHttpException extends \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
{

    /**
     * @param string     $message  The internal exception message
     * @param \Exception $previous The previous exception
     * @param int        $code     The internal exception code
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
