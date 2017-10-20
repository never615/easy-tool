<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Exception;



use Exception;

class DeleteResourceFailedException extends ResourceException
{
    public function __construct($message = null, $errors = null, Exception $previous = null, $headers = [], $code = 0)
    {
//        if (is_null($errors)) {
//            $this->errors = new MessageBag;
//        } else {
//            $this->errors = (is_array($errors) ? new MessageBag($errors) : $errors);
//        }

//        if($message==null){
//            $message=trans("errors.delete_error");
//        }
        parent::__construct($message ?:trans("errors.delete_error"), $errors, $previous, $headers, $code);
    }
}
