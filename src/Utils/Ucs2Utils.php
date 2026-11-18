<?php
/*
 * 版权所有 (c) 2023. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Utils;

/**
 * 作者： never615 <never615.com>
 * 日期： 2023/2/14
 * 时间： 12:08 下午
 */
class Ucs2Utils
{

    /***
     * @Method Ucs2Code UCS2编码
     * @Param string $str   输入字符串
     * @Param string $encod 输入字符串编码类型(UTF-8,GB2312,GBK)
     * @Return string 返回编码后的字符串
     */
    /**
     * 处理「ucs2Code」相关的内部协作步骤。
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
    /**
     * 处理「unUcs2Code」相关的内部协作步骤。
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
