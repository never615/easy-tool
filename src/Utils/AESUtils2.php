<?php
/*
 * Copyright (c) 2022. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Utils;

/**
 * User: never615 <never615.com>
 * Date: 2022/6/8
 * Time: 5:39 下午
 */
class AESUtils2
{

    /**
     * 加密
     *
     * @param String input 加密的字符串
     * @param String key   解密的key
     *
     * @return string
     */
    public function encrypt($data, $key, $method = 'AES/ECB/PKCS5Padding')
    {
        return openssl_decrypt(base64_decode($data), $method, $key, OPENSSL_RAW_DATA,
            md5(time() . uniqid(), true));

        ////AES, 128 ECB模式加密数据
        //$size = mcrypt_get_block_size(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_ECB);
        //$input = $this->pkcs5_pad($input, $size);
        //$td = mcrypt_module_open(MCRYPT_RIJNDAEL_128, '', MCRYPT_MODE_ECB, '');
        //$iv = mcrypt_create_iv(mcrypt_enc_get_iv_size($td), MCRYPT_RAND);
        //mcrypt_generic_init($td, $key, $iv);
        //
        //$data = mcrypt_generic($td, $input);
        //mcrypt_generic_deinit($td);
        //mcrypt_module_close($td);
        //$data = base64_encode($data);
        //
        //return $data;

    }


    /**
     * 解密
     *
     * @param String input 解密的字符串
     * @param String key   解密的key
     *
     * @return String
     */
    public static function decrypt($data, $key, $method = 'AES/ECB/PKCS5Padding')
    {

        return openssl_decrypt(base64_decode($data), $method, $key, OPENSSL_RAW_DATA);

        ////AES, 128 ECB模式加密数据
        //$decrypted = mcrypt_decrypt(MCRYPT_RIJNDAEL_128, $key, base64_decode($sStr), MCRYPT_MODE_ECB);
        //$dec_s = strlen($decrypted);
        //$padding = ord($decrypted[$dec_s - 1]);
        //$decrypted = substr($decrypted, 0, -$padding);
        //
        //return $decrypted;
    }


    /**
     * 填充方式 pkcs5
     *
     * @param String text          原始字符串
     * @param String blocksize   加密长度
     *
     * @return String
     */
    private static function pkcs5_pad($text, $blocksize)
    {
        $pad = $blocksize - (strlen($text) % $blocksize);

        return $text . str_repeat(chr($pad), $pad);
    }

}
