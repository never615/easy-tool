<?php
/*
 * Copyright (c) 2023. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Utils;

/**
 * User: never615 <never615.com>
 * Date: 2023/2/14
 * Time: 12:08 PM
 */
class Ucs2Utils
{

    /***
     * @Method Ucs2Code UCS2编码
     * @Param string $str   输入字符串
     * @Param string $encod 输入字符串编码类型(UTF-8,GB2312,GBK)
     * @Return string 返回编码后的字符串
     */
    public static function ucs2Code($str, $encode = "UTF-8")
    {
        $jumpbit = strtoupper($encode) == 'GB2312' ? 2 : 3;//跳转位数
        $strlen = strlen($str);//字符串长度
        $pos = 0;//位置
        $buffer = [];
        for ($pos = 0; $pos < $strlen;) {
            if (ord(substr($str, $pos, 1)) >= 0xa1) {//0xa1（161）汉字编码开始
                $tmpChar = substr($str, $pos, $jumpbit);
                $pos += $jumpbit;
            } else {
                $tmpChar = substr($str, $pos, 1);
                ++$pos;
            }
            $buffer[] = bin2hex(iconv("UTF-8", "UCS-2BE", $tmpChar));
        }

        return strtoupper(join("", $buffer));
    }


    /***
     * @Method unUcs2Code UCS2解码
     * @Param string $str   输入字符串
     * @Param string $encod 输入字符串编码类型(UTF-8,GB2312,GBK)
     * @Return string 返回解码后的字符串
     */
    public static function unUcs2Code($str, $encode = "UTF-8")
    {
        $strlen = strlen($str);
        $step = 4;
        $buffer = [];
        for ($i = 0; $i < $strlen; $i += $step) {
            $buffer[] = iconv("UCS-2BE", $encode, pack("H4", substr($str, $i, $step)));
        }

        return join("", $buffer);
    }
}
