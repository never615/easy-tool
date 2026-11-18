<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Exception;

use Exception;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * RateLimitExceededException：用于表达 rate limit exceeded exception 场景的应用异常。
 * 异常类型留给上层处理器识别，以输出符合调用方契约的错误响应。
 */
class RateLimitExceededException extends HttpException
{

    /**
     * 创建超出速率限制的异常实例。
     *
     * @param string     $message
     * @param \Exception $previous
     * @param array      $headers
     * @param int        $code
     *
     * @return void
     */
    public function __construct($message = null, Exception $previous = null, $headers = [], $code = 0)
    {
        if (array_key_exists('X-RateLimit-Reset', $headers)) {
            $headers['Retry-After'] = $headers['X-RateLimit-Reset'] - time();
        }

//        parent::__construct(429, $message ?: 'You have exceeded your rate limit.', $previous, $headers, $code);
        parent::__construct(429, $message ?: trans("errors.rate_limit"), $previous, $headers, $code);
    }
}
