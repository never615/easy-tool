<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Exception;


use Exception;
use Illuminate\Support\MessageBag;

/**
 * Class ResourceException
 *
 * @package App\Exceptions
 */
class ResourceException extends HttpException implements MessageBagErrors
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
     * @param null                                 $errCode
     * @param \Exception                           $previous
     * @param array                                $headers
     * @param int                                  $code
     *
     */
    public function __construct(
        $message = null,
        $errors = null,
        $errCode = null,
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
        parent::__construct(422, $message, $errCode, $previous, $headers, $code);
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
        return !$this->errors->isEmpty();
    }
}
