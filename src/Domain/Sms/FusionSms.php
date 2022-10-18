<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Sms;

use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Carbon;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Domain\Net\AbstractAPI;
use Mallto\Tool\Exception\ResourceException;
use Mallto\Tool\Exception\ThirdPartException;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2018/8/1
 * Time: 下午7:50
 */
class FusionSms extends AbstractAPI implements Sms
{

    protected $slug = '融合通信';

    const SETTING_KEY_RH_SMS_URL = "rh_sms_url";
    const SETTING_KEY_RH_SMS_ACCOUNT = "rh_sms_account";
    const SETTING_KEY_RH_AUTHORIZATION_CODE = "rh_authorization_code";


    /**
     * 发送单条短信
     *
     * @param $templateParam                 消息内容
     * @param $smsTemplateCode               业务编码
     * @param $mobile                        收信人列表
     * @param $content                       短信内容
     *
     * @return mixed
     */
    public function sendSms(
        $mobile,
        $smsTemplateCode,
        $templateParam,
        $smsSign = null,
        $subjectId = null,
        $content = null
    ) {
        $params = [];

        //请求地址
        $url = $this->getUrl($subjectId);
        //账号
        $account = $this->getAccount($subjectId);
        //发送授权码
        $authorization_code = $this->getAuthorizationCode($subjectId);

        //必填: 业务编码
        $params["bizCode"] = $smsTemplateCode;

        //必填: 收信人列表
        $params["toList"] = [ (string) $mobile ];

        //必填: 消息类型:0:短信
        $params["msgType"] = 0;

        //必填: 消息内容，最大长度小于1000
        $params['content'] = $content;

        //处理请求头
        $headers = [];

        $unixTime = Carbon::now()->format('YmdHis');
        //验证信息:使用Base64编码（账号 + 英文冒号 + 时间戳），时间戳是当前系统时间，格式yyyyMMddHHmmss
        $headers['X-Auth'] = base64_encode($account . ':' . $unixTime);

        //签名信息:使用SHA1加密（账号 + 发送授权码 + 时间戳），时间戳与X-Auth中的相同
        $headers['X-Sign'] = sha1($account . $authorization_code . $unixTime);

        $headers['Content-Type'] = 'application/json';
        $headers['Accept'] = 'application/json';

        try {
            $contents = $this->parseJSON(
                'POST',
                [
                    $url,
                    json_encode($params),
                    [
                        'headers' => $headers,
                    ],
                ]
            );

            return true;
        } catch (ClientException $clientException) {
            \Log::error("融合通信 client exception");
            \Log::warning($clientException->getMessage());
            \Log::warning($clientException->getResponse()->getBody());
            throw new ResourceException("请重试");
        } catch (ResourceException $resourceException) {
            throw $resourceException;
        } catch (\Exception $exception) {
            \Log::error("融合通信:数据解析错误");
            \Log::warning($exception);

            return false;
        }
    }


    private function getAccount($subjectId)
    {
        if ($subjectId) {
            return SubjectUtils::getDynamicKeyConfigByOwner(self::SETTING_KEY_RH_SMS_ACCOUNT, $subjectId,
                'znwx');
        } else {
            return 'znwx';
        }
    }


    private function getAuthorizationCode($subjectId)
    {
        if ($subjectId) {
            return SubjectUtils::getDynamicKeyConfigByOwner(self::SETTING_KEY_RH_AUTHORIZATION_CODE,
                $subjectId,
                'pdEKIusgG9');
        } else {
            return 'pdEKIusgG9';
        }
    }


    private function getUrl($subjectId)
    {
        if ($subjectId) {
            return SubjectUtils::getDynamicKeyConfigByOwner(self::SETTING_KEY_RH_SMS_URL, $subjectId,
                '104.0.44.119:30020/api/v3.0/msg/send/direct');
        } else {
            return '104.0.44.119:30020/api/v3.0/msg/send/direct';
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
    protected
    function checkAndThrow(
        array $contents
    ) {
        switch ($contents['code']) {
            case "0":
                break;
            //case "isv.BUSINESS_LIMIT_CONTROL":
            //    //throw new ResourceException("一分钟内只能发送一条短信");
            //    throw new ResourceException("今日发送短信超标，无法在发送短信");
            //    break;
            default:
                \Log::warning("短信发送失败");
                \Log::warning($contents);
                throw new ResourceException("短信发送失败:" . $contents['code'] . "," . $contents["msg"]);
                break;
        }
    }


    /**
     * 群发短信
     *
     * @param $mobiles         array 手机号
     * @param $smsSigns        array 短信签名
     * @param $smsTemplateCode string 模板号
     * @param $templateParams  string 模板参数
     *
     * @return mixed
     */
    public
    function sendBatchSms(
        $mobiles,
        $smsSigns,
        $smsTemplateCode,
        $templateParams
    ) {

    }


    /**
     * 查询发送状态
     *
     * @return mixed
     */
    public
    function querySendDetails()
    {
        // TODO: Implement querySendDetails() method.
    }
}
