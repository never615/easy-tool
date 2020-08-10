<?php
/**
 * Copyright (c) 2020. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Utils;

/**
 * User: never615 <never615.com>
 * Date: 2020/8/10
 * Time: 11:49 下午
 */
class ArrayUtils
{

    /**
     * 二维数组去重
     * 因为某一键名的值不能重复，删除重复项
     *
     * @param $arr
     * @param $key
     *
     * @return mixed
     */
    public static function arrayUnique2($arr, $key)
    {
        $tmp_arr = [];
        foreach ($arr as $k => $v) {
            if (in_array($v[$key], $tmp_arr)) {//搜索$v[$key]是否在$tmp_arr数组中存在，若存在返回true
                unset($arr[$k]);
            } else {
                $tmp_arr[] = $v[$key];
            }
        }
        sort($arr); //sort函数对数组进行排序

        return $arr;
    }
}
