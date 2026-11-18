<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Exception;

use Exception;
use Illuminate\Support\MessageBag;

/**
 * ResourceException 类
 *
 * @package App\Exceptions
 */
class ResourceException extends HttpException implements MessageBagErrors
{

    /**
     * 错误消息集合。
     *
     * @var \Illuminate\Support\MessageBag
     */
    protected $errors;


    /**
     * 创建资源异常实例。
     *
     * @param string                               $message
     * @param \Illuminate\Support\MessageBag|array $errors
     * @param \Exception                           $previous
     * @param array                                $headers
     * @param int                                  $code
     *
     */
    public function __construct(
        $message = null,
        $errors = null,
        Exception $previous = null,
        $headers = [],
        $code = 0
    ) {
        if (is_null($errors)) {
            $this->errors = new MessageBag;
        } else {
            if (is_array($errors)) {
                $message = current($errors);
                $this->errors = new MessageBag($errors);
            } else {
                $message = $errors;
                $this->errors = $errors;
            }
        }
        parent::__construct(422, $message, $code, null, $previous, $headers);
    }


    /**
     * 获取错误消息集合。
     *
     * @return \Illuminate\Support\MessageBag
     */
    public function getErrors()
    {
        return $this->errors;
    }


    /**
     * 判断消息集合是否包含错误。
     *
     * @return bool
     */
    public function hasErrors()
    {
        return ! $this->errors->isEmpty();
    }
}
