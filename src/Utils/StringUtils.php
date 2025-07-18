<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Utils;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2019/6/11
 * Time: 3:38 PM
 */
class StringUtils
{

    //    const KeyCode = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_$';
    const KeyCode = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';


    /**
     * 将64进制的数字字符串转为10进制的数字字符串
     *
     * @param $m   string 64进制的数字字符串
     * @param $len integer 返回字符串长度，如果长度不够用0填充，0为不填充
     *
     * @return string
     * @author
     */
    public static function hex64to10($m, $len = 0)
    {
        $m = (string)$m;
        $hex2 = '';
        $Code = self::KeyCode;
        for ($i = 0, $l = strlen($Code); $i < $l; $i++) {
            $KeyCode[] = $Code[$i];
        }
        $KeyCode = array_flip($KeyCode);

        for ($i = 0, $l = strlen($m); $i < $l; $i++) {
            $one = $m[$i];
            $hex2 .= str_pad(decbin($KeyCode[$one]), 6, '0', STR_PAD_LEFT);
        }
        $return = bindec($hex2);

        if ($len) {
            $clen = strlen($return);
            if ($clen >= $len) {
                return $return;
            } else {
                return str_pad($return, $len, '0', STR_PAD_LEFT);
            }
        }

        return $return;
    }


    /**
     * 将10进制的数字字符串转为64进制的数字字符串
     *
     * @param $m   string 10进制的数字字符串
     * @param $len integer 返回字符串长度，如果长度不够用0填充，0为不填充
     *
     * @return string
     * @author
     */
    public static function hex10to64($m, $len = 0)
    {
        $KeyCode = self::KeyCode;
        $hex2 = decbin($m);
        $hex2 = self::str_rsplit($hex2, 6);
        $hex64 = [];
        foreach ($hex2 as $one) {
            $t = bindec($one);
            $hex64[] = $KeyCode[$t];
        }
        $return = preg_replace('/^0*/', '', implode('', $hex64));
        if ($len) {
            $clen = strlen($return);
            if ($clen >= $len) {
                return $return;
            } else {
                return str_pad($return, $len, '0', STR_PAD_LEFT);
            }
        }

        return $return;
    }


    /**
     * 功能和PHP原生函数str_split接近，只是从尾部开始计数切割
     *
     * @param $str string 需要切割的字符串
     * @param $len integer 每段字符串的长度
     *
     * @return array|bool
     * @author
     */
    protected static function str_rsplit($str, $len = 1)
    {
        if ($str == null || $str == false || $str == '') {
            return false;
        }
        $strlen = strlen($str);
        if ($strlen <= $len) {
            return [$str];
        }
        $headlen = $strlen % $len;
        if ($headlen == 0) {
            return str_split($str, $len);
        }
        $return = [substr($str, 0, $headlen)];

        return array_merge($return, str_split(substr($str, $headlen), $len));
    }


    /**
     * 解析16进制数据为GBK编码的字符串
     *
     * @param $hex
     *
     * @return string
     */
    public static function hex2GBKString($hex)
    {
        $string = '';
        for ($i = 0; $i < strlen($hex) - 1; $i += 2) {
            $string .= chr(hexdec($hex[$i] . $hex[$i + 1]));
        }

        $string = iconv("GBK", "utf-8", $string);

        return $string;
    }


    public static function gbkString2Hex($string)
    {
        $string = iconv("utf-8", "GBK", $string);

        $hex = '';
        for ($i = 0, $iMax = strlen($string); $i < $iMax; $i++) {
            $hex .= dechex(ord($string[$i]));
        }

        return $hex;
    }


    /**
     * 十进制转十六进制函数
     *
     * @pream string $str;
     */
    public static function hex10to16($str)
    {
        $hex = "";
        for ($i = 0, $iMax = strlen($str); $i < $iMax; $i++) {
            $hex .= dechex(ord($str[$i]));
        }
        $hex = strtoupper($hex);

        return $hex;
    }


    /**
     * 十六进制转十进制
     *
     * @pream string $hex;
     */
    public static function hex16to10($hex)
    {
        $str = "";
        for ($i = 0; $i < strlen($hex) - 1; $i += 2) {
            $str .= chr(hexdec($hex[$i] . $hex[$i + 1]));
        }

        return $str;
    }


    public static function hexToBinary($hex) {
        // 使用 base_convert 函数将十六进制转换为二进制
        $binary = base_convert($hex, 16, 2);
        // 计算补齐后的二进制长度
        $padLength = strlen($hex) * 4;
        // 补齐前导零
        return str_pad($binary, $padLength, '0', STR_PAD_LEFT);
    }

    /**
     * 十六进制字符串转换成二进制字符串  010000110这种
     * @param $hexValue
     * @return string
     */
    public static function hex2binStr($hexValue)
    {
        // 将十六进制数转换为十进制数
        $decimalValue = hexdec($hexValue);

        // 将十进制数转换为二进制字符串
        $binaryString = decbin($decimalValue);


        // 确保二进制字符串具有固定的长度（8位）
        return str_pad($binaryString, 8, '0', STR_PAD_LEFT);
    }

    public static function binStr2Hex($binaryString)
    {
        // 将二进制字符串转换为十进制数
        $decimalValue = bindec($binaryString);

        // 将十进制数转换为十六进制字符串
        $hexString = dechex($decimalValue);

        // 确保十六进制字符串具有固定的长度（如果需要）
        return str_pad($hexString, 2, '0', STR_PAD_LEFT);
    }

    /**
     * 添加冒号
     * @param $mac
     * @return string
     */
    public static function macAddColon($mac)
    {
        // 使用chunk_split将字符串每两个字符分隔一次，并在每一块后面添加一个冒号
        $formatted_str = chunk_split(strtoupper($mac), 2, ':');
        // 使用rtrim移除最后的一个冒号
        return rtrim($formatted_str, ':');
    }

    /**
     * 移除冒号
     * @param $mac
     * @return string
     */
    public static function macRemoveColon($mac)
    {
        // 使用str_replace移除所有的冒号
        return str_replace(':', '', $mac);
    }


}
