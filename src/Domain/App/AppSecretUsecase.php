<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\App;

use Mallto\Tool\Domain\Net\AbstractAPI;
use Mallto\Tool\Exception\ThirdPartException;
use Mallto\Tool\Utils\ConfigUtils;
use Mallto\Tool\Utils\SignUtils;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2019/3/14
 * Time: 5:07 PM
 */
class AppSecretUsecase extends AbstractAPI
{

    public function update()
    {
        if (config("app.env") == 'production' || config("app.env") == 'staging') {
            $baseUrl = "https://wechat.mall-to.com";
        } else {
            $baseUrl = "https://test-wechat.mall-to.com";
        }

        $requestData = [];

        $sign = SignUtils::sign($requestData, config("other.mallto_app_secret"));


        $contents = $this->parseJson('get', [
            $baseUrl.'/api/app_secret',
            array_merge($requestData, [
                "sign" => $sign,
            ]),
            [
                'headers' => [
                    'app-id' => config("other.mallto_app_id"),
                    'Accept' => 'application/json',
                ],
            ],
        ]);

        \Log::debug($contents);

        ConfigUtils::set("app_secret", $contents["app_secret"]);
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