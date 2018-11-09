<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Wechat;


use GuzzleHttp\Exception\ClientException;
use Mallto\Tool\Domain\Net\AbstractAPI;
use Mallto\Tool\Utils\SignUtils;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2018/9/05
 * Time: 下午4:17
 */
class  WechatUsecase extends AbstractAPI
{

    protected $slug = 'open_platform';


    /**
     * 发送模板消息
     *
     * @param $content
     * @param $subject
     * @return bool
     */
    public function templateMsg($content, $subject)
    {
        if (config("app.env") == 'production' || config("app.env") == 'staging') {
            $baseUrl = "https://wechat.mall-to.com";
        } else {
            $baseUrl = "https://test-wechat.mall-to.com";
        }

        try {
            $content = $this->parseJSON('post', [
                $baseUrl.'/api/template_msg',
                $content,
                [
                    'headers' => [
                        'app-id'       => '1',
                        'REQUEST-TYPE' => 'SERVER',
                        'UUID'         => $subject->uuid,
                        'Accept'       => 'application/json',
                    ],
                ],
            ]);
            if ($content['code'] == 0) {
                return true;
            } else {
                if (!starts_with($content['msg'], "require subscribe")) {
                    \Log::warning("微信模板消息发送失败1");
                    \Log::warning($content['msg']);
                }

                return false;
            }

        } catch (ClientException $clientException) {
            \Log::error("微信模板消息发送失败2");
            $response = $clientException->getResponse();
            \Log::warning($clientException->getMessage());
            \Log::warning($response->getBody()->getContents());

            return false;

        } catch (\Exception $exception) {
            \Log::error("微信模板消息发送失败3");
            \Log::warning($exception->getMessage());

            return false;
        }

    }


    /**
     * 获取模板消息id
     *
     * @param $shortId
     * @param $subject
     * @return bool
     */
    public function addTemplateId($shortId, $subject)
    {
        if (config("app.env") == 'production' || config("app.env") == 'staging') {
            $baseUrl = "https://wechat.mall-to.com";
        } else {
            $baseUrl = "https://test-wechat.mall-to.com";
        }

        $requestData = [
            "short_id" => $shortId,
        ];
        $sign = SignUtils::sign($requestData, '81eaaa7cd5b8aafc51aa1e5392ae25f2');

        try {
            $content = $this->parseJSON('post', [
                $baseUrl.'/api/add_template_id',
                array_merge($requestData, [
                    "sign" => $sign,
                ]),
                [
                    'headers' => [
                        'app-id'       => '1',
                        'REQUEST-TYPE' => 'SERVER',
                        'UUID'         => $subject->uuid,
                        'Accept'       => 'application/json',
                    ],
                ],
            ]);

            return $content["template_id"];
        } catch (ClientException $clientException) {
            \Log::error("获取模板消息id 1");
            $response = $clientException->getResponse();
            \Log::warning($clientException->getMessage());
            \Log::warning($response->getBody()->getContents());

            return false;

        } catch (\Exception $exception) {
            \Log::error("获取模板消息id 2");
            \Log::warning($exception->getMessage());

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
     * @throws \Mallto\Tool\Exception\ThirdPartException
     */
    protected function checkAndThrow(
        array $contents
    ) {
        // TODO: Implement checkAndThrow() method.
    }
}