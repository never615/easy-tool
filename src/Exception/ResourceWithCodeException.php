<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Exception;

use Exception;
use Illuminate\Support\MessageBag;

/**
 * Class ResourceException
 *
 * @package App\Exceptions
 */
class ResourceWithCodeException extends ResourceException implements MessageBagErrors
{

    /**
     * MessageBag errors.
     *
     * @var \Illuminate\Support\MessageBag
     */
    protected $errors;


    /**
     * Create a new resource exception instance.
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
        $code = 0,
        $errors = null,
        Exception $previous = null,
        $headers = []
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
        parent::__construct($message, $errors, $previous, $headers, $code);
    }


    /**
     * Get the errors message bag.
     *
     * @return \Illuminate\Support\MessageBag
     */
    public function getErrors()
    {
        return $this->errors;
    }


    /**
     * Determine if message bag has any errors.
     *
     * @return bool
     */
    public function hasErrors()
    {
        return ! $this->errors->isEmpty();
    }
}
