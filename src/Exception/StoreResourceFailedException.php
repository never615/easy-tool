<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Exception;

use Exception;

class StoreResourceFailedException extends ResourceException
{

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
