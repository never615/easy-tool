<?php
/**
 * 版权所有 (c) 2018. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

if ( ! function_exists('json_decode1')) {


    /**
     * 处理「json_decode1」相关的内部协作步骤。
     */
    function json_decode1($json, $assoc = false, $depth = 512, $options = 0)
    {
        if (is_array($json)) {
            return $json;
        }

        return json_decode($json, $assoc, $depth, $options);
    }
}

