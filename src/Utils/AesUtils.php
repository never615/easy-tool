<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Utils;

use Mallto\Tool\Exception\ResourceException;

/**
 * 微信AES加密工具类
 *
 * @deprecated
 *
 * AesUtils 类
 *
 * @package Mallto\Tool\Utils
 */
class AesUtils
{

    /**
     * @var $aesKey
     */
    private $aesKey;

    /**
     * key值长度
     */
    const KEY_LENGTH_BYTE = 32;

    /**
     *
     */
    const AUTH_TAG_LENGTH_BYTE = 16;


    /**
     * AesUtils 构造函数。
     *
     * @param $aesKey
     */
    public function __construct($aesKey)
    {
        if (strlen($aesKey) != self::KEY_LENGTH_BYTE) {
            throw new ResourceException('无效的ApiV3Key，长度应为32个字节');
        }

        $this->aesKey = $aesKey;
    }


    /**
     * 解密 AEAD_AES_256_GCM 密文
     *
     * @param string $associatedData AES GCM 附加认证数据
     * @param string $nonceStr       AES GCM 随机数
     * @param string $ciphertext     AES GCM 密文
     *
     * @return string|bool      成功时返回解密字符串，失败时返回 FALSE
     */
    public function decryptToString($associatedData, $nonceStr, $ciphertext)
    {
        $ciphertext = \base64_decode($ciphertext);
        if (strlen($ciphertext) <= self::AUTH_TAG_LENGTH_BYTE) {
            return false;
        }

        // ext-sodium 扩展（PHP 7.2 及以上默认安装）
        if (function_exists('\sodium_crypto_aead_aes256gcm_is_available') &&
            \sodium_crypto_aead_aes256gcm_is_available()) {
            return \sodium_crypto_aead_aes256gcm_decrypt($ciphertext, $associatedData, $nonceStr,
                $this->aesKey);
        }

        // ext-libsodium（需要通过 pecl 安装 libsodium-php 1.x）
        if (function_exists('\Sodium\crypto_aead_aes256gcm_is_available') &&
            \Sodium\crypto_aead_aes256gcm_is_available()) {
            return \Sodium\crypto_aead_aes256gcm_decrypt($ciphertext, $associatedData, $nonceStr,
                $this->aesKey);
        }

        // openssl (PHP >= 7.1 support AEAD)
        if (PHP_VERSION_ID >= 70100 && in_array('aes-256-gcm', \openssl_get_cipher_methods())) {
            $ctext = substr($ciphertext, 0, -self::AUTH_TAG_LENGTH_BYTE);
            $authTag = substr($ciphertext, -self::AUTH_TAG_LENGTH_BYTE);

            return \openssl_decrypt($ctext, 'aes-256-gcm', $this->aesKey, \OPENSSL_RAW_DATA, $nonceStr,
                $authTag, $associatedData);
        }

        throw new \RuntimeException('AEAD_AES_256_GCM需要PHP 7.1以上或者安装libsodium-php');
    }

}
