<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Utils;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2018/11/7
 * Time: 7:41 PM
 */
class DESUtils
{

    /**
     * 加密
     *
     * @param $str
     *
     * @return string
     */
    public static function encrypt($str, $key)
    {
//        $str = self::pkcsPadding($str, 8);
//        $str = self::unpadZero($str);

        if (strlen($str) % 8) {
            $str = str_pad($str,
                strlen($str) + 8 - strlen($str) % 8, "\0");
        }

        //OPENSSL_ZERO_PADDING
        //OPENSSL_RAW_DATA | OPENSSL_NO_PADDING
        $sign = openssl_encrypt($str, "DES-ECB", $key, OPENSSL_RAW_DATA | OPENSSL_NO_PADDING);

        $sign = base64_encode($sign);

//        $sign = bin2hex($sign);

        return $sign;
    }


    public static function unpadZero($data)
    {
        return rtrim($data, "\0");
    }

//    /**
//     * 解密
//     *
//     * @param $encrypted
//     * @return string
//     */
//    public function decrypt($encrypted)
//    {
//        if ($this->output == self::OUTPUT_BASE64) {
//            $encrypted = base64_decode($encrypted);
//        } else {
//            if ($this->output == self::OUTPUT_HEX) {
//                $encrypted = hex2bin($encrypted);
//            }
//        }
//
//        $sign = @openssl_decrypt($encrypted, $this->method, $key, $this->options, $this->iv);
//        $sign = $this->unPkcsPadding($sign);
//        $sign = rtrim($sign);
//
//        return $sign;
//    }
//

    /**
     * 填充
     *
     * @param $str
     * @param $blocksize
     *
     * @return string
     */
    public static function pkcsPadding($str, $blocksize)
    {
        $pad = $blocksize - (strlen($str) % $blocksize);

        return $str . str_repeat(chr($pad), $pad);
    }


//
//
//    /**
//     * 去填充
//     *
//     * @param $str
//     * @return string
//     */
//    private function unPkcsPadding($str)
//    {
//        $pad = ord($str{strlen($str) - 1});
//        if ($pad > strlen($str)) {
//            return false;
//        }
//
//        return substr($str, 0, -1 * $pad);
//    }

//
//    public static function desEncrypt($text, $key)
//    {
//        $iv_size = mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB);
//        $iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);
//
//        $encrypt_str = mcrypt_encrypt(MCRYPT_RIJNDAEL_256, $key, $text, MCRYPT_MODE_ECB, $iv);
//
//        return base64_encode($encrypt_str);
//
//    }
//
//    public static function desDecrypt($crypttext,$key)
//    {
//        $iv_size = mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB);
//        $iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);
//        return mcrypt_decrypt(MCRYPT_RIJNDAEL_256, $key, base64_decode($crypttext), MCRYPT_MODE_ECB, $iv);//解密后的内容
//    }

}
