<?php

namespace Mallto\Tool\Utils;

/**
 * PHP DES对称加密解密实例.
 *
 * Class SignByDESUtils
 */
class SignByDESUtils
{

    /**
     * [encrypt aes加密].
     *
     * @param  [type] $str [要加密的数据]
     * @param  [type] $key [加密key]
     * @param mixed $str
     *
     * @return [type] [加密后的数据]
     */
    public static function encrypt($str, $key)
    {
        //加密key
        $key = self::_sha1prng($key);

        //填充
        $strPadded = $str;

        if (strlen($strPadded) % 8) {
            $strPadded = str_pad($strPadded, strlen($strPadded) + 8 - strlen($strPadded) % 8, "\0");
        }

        $result = openssl_encrypt($strPadded, 'DES-ECB', $key, OPENSSL_NO_PADDING);

        return base64_encode($result);
    }


    /**
     * [decrypt aes解密].
     *
     * @param  [type] $str [要解密的数据]
     * @param  [type] $key [加密key]
     * @param mixed $str
     * @param mixed $key
     *
     * @return [type] [解密后的数据]
     */
    public static function decrypt($str, $key)
    {
        $key = self::_sha1prng($key);

        $data = openssl_decrypt(base64_decode($str), 'DES-ECB', $key, OPENSSL_NO_PADDING);

        //反填充
        return rtrim(rtrim($data, chr(0)), chr(7));
    }


    /**
     * SHA1PRNG算法.
     *
     * @param  [type] $key [description]
     *
     * @return [type] [description]
     */
    private static function _sha1prng($key)
    {
        return substr(openssl_digest(openssl_digest($key, 'sha1', true), 'sha1', true), 0, 16);
    }
}
