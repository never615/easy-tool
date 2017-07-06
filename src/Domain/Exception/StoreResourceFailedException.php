<?php

namespace Mallto\Tool\Domain\Exception;



use Exception;
use Illuminate\Support\MessageBag;

class StoreResourceFailedException extends ResourceException
{

    public function __construct($message = null, $errors = null, Exception $previous = null, $headers = [], $code = 0)
    {
        
//        if($message==null){
//            $message=trans("errors.store_error");
//        }
        parent::__construct($message ?:trans("errors.store_error"), $errors, $previous, $headers, $code);
    }

}
