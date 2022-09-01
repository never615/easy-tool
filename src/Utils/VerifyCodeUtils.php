<?php
/*
 * Copyright (c) 2022. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Utils;

/**
 * User: never615 <never615.com>
 * Date: 2022/9/1
 * Time: 5:20 PM
 */
class VerifyCodeUtils
{

    /**
     * 从头开始，同后一字节异或
     *
     * hex数据BBC异或校验 $str为需要校验的字符串
     *
     * @param $data 16进制字符串
     *
     * @return void
     */
    public static function hexXorArr($str)
    {
        $data = [];
        $strLen = strlen($str);
        if ($strLen % 2 == 0) {
            for ($i = 0; $i < $strLen; $i = $i + 2) {
                $data[] = substr($str, $i, 2);
            }
        }
        $result = $data[0];
        for ($j = 0; $j < count($data) - 1; $j++) {
            $result = self::hexXor($result, $data[$j + 1]);
        }
        if (strlen($result) === 1) {
            $result = '0' . $result;
        }

        return $result;
    }


    private static function hexXor($hex1, $hex2)
    {
        $bin1 = str_pad(base_convert($hex1, 16, 2), 16, '0', STR_PAD_LEFT);
        $bin2 = str_pad(base_convert($hex2, 16, 2), 16, '0', STR_PAD_LEFT);
        $result = '';

        for ($i = 0; $i < 16; $i++) {
            $result .= $bin1[$i] == $bin2[$i] ? '0' : '1';
        }

        return base_convert($result, 2, 16);
    }
}
