<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

/**
 * 签名规则:
 * 1.需要签名的参数使用key值,按照字典序从小到大排序
 * 2.参数拼接,key=value形式拼接,使用&相连接,需要url转码.
 * 3.处理完的参数最后在拼接上秘钥
 * 4.md5加密,全部转为小写
 * 5.校验签名的时候,不包括签名参数sign
 *
 * Created by PhpStorm.
 * User: never615
 * Date: 5/5/16
 * Time: 3:31 PM
 */

namespace Mallto\Tool\Utils;

use Mallto\Tool\Exception\SignException;

class SignUtils
{

    //墨兔提供的接口的key
    private static $mallto_key = 'f334bd635eaf5bcaf135e30171bb11em';


    /**
     * 签名
     * 最后签名最结果为小写
     *
     * @param array $arr
     * @param       $key
     *
     * @return string
     */
    public static function sign(array $arr, $key)
    {
//        $arr=array_filter($arr);

        ksort($arr, SORT_STRING);
        $tmpHttp = http_build_query($arr);
        $tmpHttp = urldecode($tmpHttp);

        if ($tmpHttp) {
            $stringSignTemp = $tmpHttp . '&key=' . $key;
        } else {
            $stringSignTemp = 'key=' . $key;
        }
//        \Log::info($stringSignTemp);
        $stringSignTemp = base64_encode($stringSignTemp);
//        \Log::info('base64以后:'.$stringSignTemp);

        $sign = strtolower(md5($stringSignTemp));

        return $sign;
    }


    /**
     * 签名
     *
     * @param array $arr
     * @param       $key
     *
     * @return string
     * @deprecated
     *
     */
    public static function signByLower(array $arr, $key)
    {
//        $arr=array_filter($arr);

        ksort($arr, SORT_STRING);
        $tmpHttp = http_build_query($arr);
        $stringSignTemp = $tmpHttp . '&secret=' . $key;

        $stringSignTemp = urldecode($stringSignTemp);

//        \Log::info($stringSignTemp);

        return strtolower(md5($stringSignTemp));
    }


    /**
     * 签名校验
     *
     *      * @param array $arr
     * @param null $key
     *
     * @return bool
     * @deprecated
     *
     */
    public static function verifySign(array $arr, $key = null)
    {
        if (is_null($key)) {
            $key = self::$mallto_key;
        }

        if (!isset($arr['sign'])) {
            throw new SignException("缺少sign字段");
        }

        $waiteSign = $arr['sign'];
        unset($arr['sign']);

        ksort($arr, SORT_STRING);

        $tmpHttp = http_build_query($arr);
        $tmpHttp = urldecode($tmpHttp);

        if ($tmpHttp) {
            $stringSignTemp = $tmpHttp . '&key=' . $key;
        } else {
            $stringSignTemp = 'key=' . $key;
        }

//        \Log::info($stringSignTemp);
        $stringSignTemp = base64_encode($stringSignTemp);
//        \Log::info('base64以后:'.$stringSignTemp);

        $sign = strtolower(md5($stringSignTemp));

        if (array_key_exists('timestamp', $arr)) {
            //如果有时间戳校验时间戳
            $timeInterval = time() - $arr['timestamp'];
//            Log::info('timeInterval:'.$timeInterval);
            if ($timeInterval > 10) {
                return false;
            }
        }
//        \Log::info($arr);
//        \Log::info($waiteSign);
//        \Log::info($sign);
        return $sign == $waiteSign ? true : false;
    }


    /**
     * 签名校验
     *
     * 签名2.0/3.0 版本
     *
     * https://wiki.mall-to.com/web/#/27?page_id=234
     *
     * @param array $arr
     * @param null $secret
     *
     * @return string
     */
    public static function verifySign2(array $arr, $secret)
    {
        if (!isset($arr['signature'])) {
            throw new SignException("缺少signature字段");
        }

        $waiteSign = $arr['signature'];
        unset($arr['signature']);
        ksort($arr, SORT_STRING);

        $stringToSign = http_build_query($arr);
        $stringToSign = urldecode($stringToSign);
        $stringToSign = rawurlencode($stringToSign);

        $sign = base64_encode(hash_hmac('sha1', $stringToSign, $secret, true));

        return $sign == $waiteSign ? true : false;
    }


    /**
     * 签名2.0/3.0
     *
     * https://wiki.mall-to.com/web/#/27?page_id=234
     * @param array $arr 待签名数据
     * @param string $secret 签名秘钥
     *
     * @return string
     */
    public static function signVersion2($arr, $secret)
    {
        ksort($arr, SORT_STRING);

        $stringToSign = http_build_query($arr);
        $stringToSign = urldecode($stringToSign);
        //\Log::debug("signVersion2 2:" . $stringToSign);
        $stringToSign = rawurlencode($stringToSign);

        //\Log::debug("signVersion2 3:" . $stringToSign);

        return base64_encode(hash_hmac('sha1', $stringToSign, $secret, true));
    }


    /**
     * 签名校验
     *
     * 签名4.0版本
     *
     * https://wiki.mall-to.com/web/#/27?page_id=232
     *
     * @param array $arr
     * @param null $secret
     *
     * @return string
     */
    public static function verifySign4(array $arr, $secret)
    {
        if (!isset($arr['signature'])) {
            throw new SignException("缺少signature字段");
        }

        $waiteSign = $arr['signature'];
        \Log::debug('$waiteSign:' . $waiteSign);
        unset($arr['signature']);
        ksort($arr, SORT_STRING);
        \Log::debug($arr);

        $stringToSign = http_build_query($arr);
        $stringToSign = urldecode($stringToSign);
        $stringToSign = rawurlencode($stringToSign);
        \Log::debug('$stringToSign:' . $stringToSign);

        $sign = rawurlencode(base64_encode(hash_hmac('sha1', $stringToSign, $secret, true)));

        \Log::debug('$sign:' . $sign);

        return $sign == $waiteSign ? true : false;
    }


    /**
     * 签名4.0
     *
     * https://wiki.mall-to.com/web/#/27?page_id=232
     * @param array $arr 待签名数据
     * @param string $secret 签名秘钥
     *
     * @return string
     */
    public static function signVersion4($arr, $secret)
    {
        ksort($arr, SORT_STRING);

        $stringToSign = http_build_query($arr);
        $stringToSign = urldecode($stringToSign);
        $stringToSign = rawurlencode($stringToSign);

        return rawurlencode(base64_encode(hash_hmac('sha1', $stringToSign, $secret, true)));
    }

}
