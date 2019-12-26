<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Exception;

use Exception;

class UpdateResourceFailedException extends ResourceException
{

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
