<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

if ( ! function_exists('json_decode1')) {


    function json_decode1($json, $assoc = false, $depth = 512, $options = 0)
    {
        if (is_array($json)) {
            return $json;
        }

        return json_decode($json, $assoc, $depth, $options);
    }
}



