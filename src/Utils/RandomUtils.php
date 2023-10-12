<?php
/*
 * Copyright (c) 2023. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Utils;

/**
 * User: never615 <never615.com>
 * Date: 2023/10/12
 * Time: 19:10
 */
class RandomUtils
{

    /**
     * 生成随机字符串
     *
     * @param int    $length 要生成的随机字符串长度
     * @param string $type   随机码类型：0，数字+大小写字母；1，数字；2，小写字母；3，大写字母；4，特殊字符；-1，数字+大小写字母+特殊字符
     *
     * @return string
     */
    public static function randCode($length = 16, $type = -1)
    {
        $arr = [
            1 => "0123456789",
            2 => "abcdefghijklmnopqrstuvwxyz",
            3 => "ABCDEFGHIJKLMNOPQRSTUVWXYZ",
            4 => "~@#$%^&*(){}[]|",
        ];
        if ($type == 0) {
            array_pop($arr);
            $string = implode("", $arr);
        } elseif ($type == "-1") {
            $string = implode("", $arr);
        } else {
            $string = $arr[$type];
        }

        $count = strlen($string) - 1;
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $string[rand(0, $count)];
        }

        return $code;
    }
}