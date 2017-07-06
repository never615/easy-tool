<?php

namespace Mallto\Tool\Domain\Exception;



use Exception;

class UpdateResourceFailedException extends ResourceException
{
    public function __construct($message = null, $errors = null, Exception $previous = null, $headers = [], $code = 0)
    {


//        if($message==null){
//            $message=trans("errors.update_error");
//        }
        parent::__construct($message ?: trans("errors.update_error"), $errors, $previous, $headers, $code);
    }
}
