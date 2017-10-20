<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Exception;


/**
 * Created by PhpStorm.
 * User: never615
 * Date: 28/11/2016
 * Time: 5:20 PM
 */
interface MessageBagErrors
{
    /**
     * Get the errors message bag.
     *
     * @return \Illuminate\Support\MessageBag
     */
    public function getErrors();
    /**
     * Determine if message bag has any errors.
     *
     * @return bool
     */
    public function hasErrors();
}