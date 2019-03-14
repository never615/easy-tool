<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Wechat;


use GuzzleHttp\Exception\ClientException;
use Mallto\Mall\SubjectConfigConstants;
use Mallto\Tool\Domain\Net\AbstractAPI;
use Mallto\Tool\Exception\ResourceException;
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
     * @param      $content
     * @param      $subject
     * @param bool $isAdminNotify
     * @return bool
     */
    public function templateMsg($content, $subject, $isAdminNotify = false)
    {
        if (config("app.env") == 'production' || config("app.env") == 'staging') {
            $baseUrl = "https://wechat.mall-to.com";
        } else {
            $baseUrl = "https://test-wechat.mall-to.com";
        }

        $uuid = $subject->uuid;
        if ($isAdminNotify) {
            $uuid = $subject->extra_config[SubjectConfigConstants::OWNER_CONFIG_ADMIN_WECHAT_UUID];
        }

        try {
            $this->parseJSON('post', [
                $baseUrl.'/api/template_msg',
                $content,
                [
                    'headers' => [
                        'app-id'       => config("other.mallto_app_id"),
                        'REQUEST-TYPE' => 'SERVER',
                        'UUID'         => $uuid,
                        'Accept'       => 'application/json',
                    ],
                ],
            ]);

            return true;
        } catch (ResourceException $exception) {
            if (!starts_with($exception->getMessage(), "require subscribe")) {
                \Log::error("微信模板消息发送失败 ResourceException");
                \Log::warning($exception);
            }

            return false;
        } catch (ClientException $clientException) {
            \Log::error("微信模板消息发送失败 ClientException");
            $response = $clientException->getResponse();
            \Log::warning($clientException);
            \Log::warning($response->getBody()->getContents());

            return false;

        } catch (\Exception $exception) {
            \Log::error("微信模板消息发送失败 Exception");
            \Log::warning($exception);

            return false;
        }

    }


    /**
     * 获取/设置模板消息id
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


        $uuid = "";
        if (WechatUtils::isUserSystemTemplate($shortId)) {
            $uuid = $subject->uuid;
        } elseif (WechatUtils::isAdminSystemTemplate($shortId)) {
            $uuid = $subject->extra_config[SubjectConfigConstants::OWNER_CONFIG_ADMIN_WECHAT_UUID];
        }

        $requestData = [
            "short_id" => $shortId,
        ];
        $sign = SignUtils::sign($requestData, config('other.mallto_app_secret'));

        try {
            $content = $this->parseJSON('post', [
                $baseUrl.'/api/add_template_id',
                array_merge($requestData, [
                    "sign" => $sign,
                ]),
                [
                    'headers' => [
                        'app-id'       => config("other.mallto_app_id"),
                        'REQUEST-TYPE' => 'SERVER',
                        'UUID'         => $uuid,
                        'Accept'       => 'application/json',
                    ],
                ],
            ]);

            return $content["template_id"];
        } catch (ResourceException $resourceException) {
            throw $resourceException;
        } catch (ClientException $clientException) {
            \Log::error("获取/设置模板消息id client exception");
            $response = $clientException->getResponse();
            \Log::warning($clientException->getMessage());
            \Log::warning($response->getBody()->getContents());

            return false;

        } catch (\Exception $exception) {
            \Log::error("获取/设置模板消息id exception");
            \Log::warning($exception);

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
        if (isset($contents['code']) && $contents['code'] != 0) {
            throw new ResourceException($contents['msg']);
        }
    }
}