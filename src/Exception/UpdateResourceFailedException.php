<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Exception;

use Exception;

/**
 * UpdateResourceFailedException：用于表达 update resource failed exception 场景的应用异常。
 * 异常类型留给上层处理器识别，以输出符合调用方契约的错误响应。
 */
class UpdateResourceFailedException extends ResourceException
{

    /**
     * 注入本组件运行所需的协作依赖，保持构造与业务执行解耦。
     */
    public function __construct(
        $message = null,
        $errors = null,
        Exception $previous = null,
        $headers = [],
        $code = 0
    ) {
        parent::__construct($message ?: trans("errors.update_error"), $errors, $previous, $headers, $code);
    }
}
