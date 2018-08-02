<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Traits;

/**
 * Trait AliyunTrait
 *
 * @package Mallto\Tool\Domain\Traits
 */
trait AliyunTrait
{
    /**
     * 签名
     *
     * @param        $params
     * @param string $method
     * @return string
     */
    protected function mergePublicParamsAndSign($params,$method="GET")
    {
        //必填: 请参阅 https://ak-console.aliyun.com/ 取得您的AK信息
        $accessKeyId = config("app.aliyun_log_access_key_id");
        $accessKeySecret = config("app.aliyun_log_access_key");

        $apiParams = array_merge(array (
            "SignatureMethod"  => "HMAC-SHA1",
            "SignatureNonce"   => uniqid(mt_rand(0, 0xffff), true),
            "SignatureVersion" => "1.0",
            "AccessKeyId"      => $accessKeyId,
            "Timestamp"        => gmdate("Y-m-d\TH:i:s\Z"),
            "Format"           => "JSON",
        ), $params);
        ksort($apiParams);

        $sortedQueryStringTmp = "";
        foreach ($apiParams as $key => $value) {
            $sortedQueryStringTmp .= "&".$this->encode($key)."=".$this->encode($value);
        }

        $stringToSign = "$method&%2F&".$this->encode(substr($sortedQueryStringTmp, 1));

        $sign = base64_encode(hash_hmac("sha1", $stringToSign, $accessKeySecret."&", true));

        $signature = $this->encode($sign);

        return "Signature={$signature}{$sortedQueryStringTmp}";
    }


    protected function encode($str)
    {
        $res = urlencode($str);
        $res = preg_replace("/\+/", "%20", $res);
        $res = preg_replace("/\*/", "%2A", $res);
        $res = preg_replace("/%7E/", "~", $res);

        return $res;
    }
}
