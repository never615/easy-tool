<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\App;

use GuzzleHttp\Exception\ClientException;
use Mallto\Tool\Data\Config;
use Mallto\Tool\Domain\Net\AbstractAPI;
use Mallto\Tool\Exception\ThirdPartException;
use Mallto\Tool\Utils\ConfigUtils;
use Mallto\Tool\Utils\SignUtils;
use Illuminate\Support\Facades\Log;

/**
 *
 * 请求微信开放平台,更新当前应用的秘钥
 *
 * 秘钥用做请求定位接口,如果没有按期更新秘钥,则会无权限请求相应的接口
 *
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2019/3/14
 * Time: 5:07 PM
 */
class AppSecretUsecase extends AbstractAPI
{

    protected $slug = "open_platform";


    public function update()
    {
        $salt = "phfOtwKclusrHKwfgPtfIah1uT3xi";
        $app_secret = md5(md5($salt . date('Ymd') . $salt));
        ConfigUtils::set(Config::APP_SECRET, $app_secret);

        return;

        if (config("app.env") == 'production' || config("app.env") == 'staging') {
            $baseUrl = "https://wechat.mall-to.com";
        } else {
            $baseUrl = "https://test-wechat.mall-to.com";
        }

//        $nonceStr = AppUtils::getRandomString();
//        $now = TimeUtils::getNowTime();
//
//        $signHeaders = [
//            'uuid'            => 0,
//            'app_id'          => config("other.mallto_app_id"),
//            'timestamp'       => $now,
//            'signature_nonce' => $nonceStr,
//        ];

        $requestData = [

        ];

//        $requestData = array_merge($signHeaders, $requestData);

        $sign = SignUtils::sign($requestData, config("other.mallto_app_secret"));

        $requestData = array_merge($requestData, [
            "sign" => $sign,
        ]);

        $contents = null;
        try {
            $contents = $this->parseJson('get', [
                $baseUrl . '/api/app_secret',
                $requestData,
                [
                    'headers' => [
                        'uuid'   => 0,
                        'app-id' => config("other.mallto_app_id"),
//                        'Timestamp' => $now,
                        'Accept' => 'application/json',
//                        'Signature-Nonce'   => $nonceStr,
//                        'Signature-Version' => 4,
//                        'Signature'         => $sign,
                    ],
                ],
            ]);

        } catch (ClientException $clientException) {
            Log::error("请求微信开放平台,更新当前应用的秘钥ClientException异常");
            Log::warning($clientException->getResponse()->getBody());
        } catch (\Exception $exception) {
            Log::error("请求微信开放平台,更新当前应用的秘钥异常");
            Log::warning($exception);
        }

        if ($contents && isset($contents["app_secret"])) {
            ConfigUtils::set(Config::APP_SECRET, $contents["app_secret"]);

            return true;
        } else {
            return false;
        }
    }


    /**
     * 不同的实现需要重写此方法 标准的json请求使用
     * Check the array data errors, and Throw exception when the contents contains error.
     *
     * @param array $contents
     *
     * @return array
     * @throws ThirdPartException
     */
    protected function checkAndThrow(
        array $contents
    ) {
        // TODO: Implement checkAndThrow() method.
    }
}
