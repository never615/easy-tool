<?php

namespace Mallto\Tool\Utils;

class SignByDESUtils
{

    /**
     * [encrypt aes加密]
     *
     * @param    [type]                   $input [要加密的数据]
     * @param    [type]                   $key   [加密key]
     *
     * @return   [type]                          [加密后的数据]
     */
    public static function encrypt($input, $key)
    {
        $key = self::_sha1prng($key);

        if (strlen($key) % 8) {
            $key = str_pad($key,
                strlen($key) + 8 - strlen($key) % 8, "\0");
        }

        $iv = '';
        $data = openssl_encrypt($input, 'AES-128-ECB', $key, OPENSSL_RAW_DATA, $iv);
        $data = base64_encode($data);

        return $data;
    }


    /**
     * SHA1PRNG算法
     *
     * @param  [type] $key [description]
     *
     * @return [type]      [description]
     */
    private static function _sha1prng($key)
    {
        return substr(openssl_digest(openssl_digest($key, 'sha1', true), 'sha1', true), 0, 16);
    }


    /**
     * [decrypt aes解密]
     *
     * @param    [type]                   $sStr [要解密的数据]
     * @param    [type]                   $sKey [加密key]
     *
     * @return   [type]                         [解密后的数据]
     */
    public static function decrypt($sStr, $sKey)
    {
        $sKey = self::_sha1prng($sKey);
        $iv = '';
        $decrypted = openssl_decrypt(base64_decode($sStr), 'AES-128-ECB', $sKey, OPENSSL_RAW_DATA, $iv);

        $pad = ord($decrypted{strlen($decrypted) - 1});

        if ($pad > strlen($decrypted)) {
            return false;
        }

        return substr($decrypted, 0, -1 * $pad);
    }

}
