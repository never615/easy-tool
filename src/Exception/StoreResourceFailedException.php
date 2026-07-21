<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Exception;

use Exception;

/**
 * StoreResourceFailedException：用于表达 store resource failed exception 场景的应用异常。
 * 异常类型留给上层处理器识别，以输出符合调用方契约的错误响应。
 */
class StoreResourceFailedException extends ResourceException
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

//        if($message==null){
//            $message=trans("errors.store_error");
//        }
        parent::__construct($message ?: trans("errors.store_error"), $errors, $previous, $headers, $code);
    }

}
