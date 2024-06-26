<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Wechat;

use Closure;
use GuzzleHttp\Exception\ClientException;
use Mallto\Admin\SubjectConfigConstants;
use Mallto\Tool\Data\WechatTemplateMsg;
use Mallto\Tool\Domain\Net\AbstractAPI;
use Mallto\Tool\Exception\ResourceException;
use Mallto\Tool\Utils\AppUtils;
use Mallto\Tool\Utils\SignUtils;
use Illuminate\Support\Facades\Log;

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
     * 对外开放的发送微信模板消息方法
     *
     * @param      $public_template_id
     * @param      $data
     * @param      $openId
     * @param      $subject
     * @param null $callback
     * @param null $url
     *
     * @return bool
     */
    public function wechatTemplateMsg(
        $public_template_id,
        $data,
        $openId,
        $subject,
        $callback = null,
        $url = null
    ) {
        $wechatTemplateMsg = WechatTemplateMsg::where("public_template_id", $public_template_id)
            ->where("subject_id", $subject->id)
            ->first();

        if ($wechatTemplateMsg) {
            if ($wechatTemplateMsg->switch) {
                if ($callback instanceof Closure) {
                    $remark = call_user_func($callback, $wechatTemplateMsg);
                }

                $templateId = $wechatTemplateMsg->template_id;
                if (isset($remark)) {
                    $data = array_merge($data, [ 'remark' => $remark ]);
                }
                $requestData = [
                    'openid'      => $openId,
                    'template_id' => $templateId,
                    'url'         => $url ?? $wechatTemplateMsg->template_link ?? null,
                    'data'        => json_encode($data),
                ];

                $sign = SignUtils::sign($requestData, config('other.mallto_app_secret'));

                return $this->templateMsg(
                    array_merge($requestData, [
                        "sign" => $sign,
                    ])
                    , $subject, $public_template_id);
            }
        } else {
            if ($public_template_id != 'OPENTM202764141') {
                Log::warning("模板消息不存在,新设置:" . $public_template_id . ",subject_id:" . $subject->id);

                $templateId = $this->addTemplateId($public_template_id, $subject);
                if ($templateId) {
                    $this->wechatTemplateMsg($public_template_id, $data, $openId, $subject, $callback);
                }
            }
        }
    }


    /**
     * 发送模板消息
     *
     * @link https://www.easywechat.com/docs/4.1/official-account/template_message#heading-h2-5
     *
     * @param      $content
     * @param      $subject
     * @param      $public_template_id
     *
     * @return bool
     */
    protected function templateMsg($content, $subject, $public_template_id)
    {
        if (config("app.env") === 'production' || config('app.env') === 'staging') {
            $baseUrl = "https://wechat.mall-to.com";
        } else {
            $baseUrl = "https://test-wechat.mall-to.com";
        }

        if (WechatUtils::isUserSystemTemplate($public_template_id)) {
            $uuid = $subject->wechat_uuid ?? $subject->uuid;
        } elseif (WechatUtils::isAdminSystemTemplate($public_template_id)) {
            $uuid = $subject->extra_config[SubjectConfigConstants::OWNER_CONFIG_ADMIN_WECHAT_UUID];
        }

        if ( ! $uuid) {
            Log::error("微信模板消息发送失败 uuid未设置");
            Log::warning($public_template_id);

            return false;
        }

        try {
            $this->parseJSON('post', [
                $baseUrl . '/api/template_msg',
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
            if ( ! starts_with($exception->getMessage(), "require subscribe")) {
                if (starts_with($exception->getMessage(), 'invalid template_id hint')) {
                    Log::warning('addTemplateId', [ $public_template_id, $subject->id ]);
                    $new_templateId = $this->addTemplateId($public_template_id, $subject,
                        $content['template_id']);
                    if ($new_templateId) {
                        $content['template_id'] = $new_templateId;
                        unset($content['sign']);
                        $sign = SignUtils::sign($content, config('other.mallto_app_secret'));
                        $content['sign'] = $sign;
                        $this->templateMsg($content, $subject, $public_template_id);
                    }
                } else {
                    Log::error("微信模板消息发送失败", [ $exception->getMessage() ]);
                    Log::warning($exception);
                }
            }

            return false;
        } catch (ClientException $clientException) {
            Log::error("微信模板消息发送失败 ClientException");
            $response = $clientException->getResponse();
            Log::warning($clientException);
            Log::warning($response->getBody()->getContents());

            return false;

        } catch (\Exception $exception) {
            Log::error("微信模板消息发送失败 Exception");
            Log::warning($exception);

            return false;
        }

    }


    /**
     * 获取/设置模板消息id
     *
     * @param      $shortId
     * @param      $subject
     * @param null $oldTemplateId 旧的模板id失效时,传旧的进来也
     *
     * @return bool
     */
    public function addTemplateId($shortId, $subject, $oldTemplateId = null)
    {
        Log::warning(new \Exception());

        if ( ! AppUtils::isTestEnv()) {
            $baseUrl = "https://wechat.mall-to.com";
        } else {
            $baseUrl = "https://test-wechat.mall-to.com";
        }

        if (WechatUtils::isUserSystemTemplate($shortId)) {
            $uuid = $subject->wechat_uuid ?? $subject->uuid;
        } elseif (WechatUtils::isAdminSystemTemplate($shortId)) {
            if(!is_array($subject->extra_config))
            {
                throw new ResourceException('管理端微信服务uuid未设置!!');
            }
            $uuid = $subject->extra_config[SubjectConfigConstants::OWNER_CONFIG_ADMIN_WECHAT_UUID];
        }

        $requestData = [
            "short_id"    => $shortId,
            "template_id" => $oldTemplateId,
        ];
        $sign = SignUtils::sign($requestData, config('other.mallto_app_secret'));

        try {
            $content = $this->parseJSON('post', [
                $baseUrl . '/api/add_template_id',
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

            if (isset($content["template_id"]) && $content["template_id"]) {
                WechatTemplateMsg::updateOrCreate([
                    "subject_id"         => $subject->id,
                    "public_template_id" => $shortId,
                ], [
                    "template_id" => $content["template_id"],
                ]);

                return $content["template_id"];
            }

            return false;
        } catch (ResourceException $resourceException) {
            Log::warning("获取/设置模板消息id resourceException");
            Log::warning($resourceException);
            throw $resourceException;
        } catch (ClientException $clientException) {
            Log::warning("获取/设置模板消息id client exception");
            Log::warning($clientException->getResponse()->getBody()->getContents());

            return false;
        } catch (\Exception $exception) {
            Log::warning("获取/设置模板消息id exception");
            Log::warning($exception);

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
