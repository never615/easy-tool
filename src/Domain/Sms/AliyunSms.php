<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Sms;

use GuzzleHttp\Exception\ClientException;
use Mallto\Tool\Domain\Net\AbstractAPI;
use Mallto\Tool\Domain\Traits\AliyunTrait;
use Mallto\Tool\Exception\ResourceException;
use Mallto\Tool\Exception\ThirdPartException;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2018/8/1
 * Time: 下午7:50
 */
class AliyunSms extends AbstractAPI implements Sms
{
    use AliyunTrait;

    protected $slug = 'aliyun_sms';

    protected $baseUrl = "https://dysmsapi.aliyuncs.com";

    /**
     * 发送单条短信
     *
     * @param $mobile          手机号
     * @param $smsSign         短信签名
     * @param $smsTemplateCode 模板号
     * @param $templateParam   模板参数
     * @return mixed
     */
    public function sendSms($mobile, $smsSign, $smsTemplateCode, $templateParam)
    {
        $params = array ();

        // *** 需用户填写部分 ***


        //必填: 短信接收号码
        $params["PhoneNumbers"] = $mobile;

        //必填: 短信签名，应严格按"签名名称"填写，请参考: https://dysms.console.aliyun.com/dysms.htm#/develop/sign
        $params["SignName"] = $smsSign;

        //必填: 短信模板Code，应严格按"模板CODE"填写, 请参考: https://dysms.console.aliyun.com/dysms.htm#/develop/template
        $params["TemplateCode"] = $smsTemplateCode;

        //可选: 设置模板参数, 假如模板中存在变量需要替换则为必填项
        $params['TemplateParam'] = $templateParam;

        //可选: 设置发送短信流水号
        //$params['OutId'] = "12345";

        //可选: 上行短信扩展码, 扩展码字段控制在7位或以下，无特殊需求用户请忽略此字段
        //$params['SmsUpExtendCode'] = "1234567";


        // *** 需用户填写部分结束, 以下代码若无必要无需更改 ***
        if (!empty($params["TemplateParam"]) && is_array($params["TemplateParam"])) {
            $params["TemplateParam"] = json_encode($params["TemplateParam"], JSON_UNESCAPED_UNICODE);
        }

        $params = array_merge($params, array (
            "RegionId" => "cn-hangzhou",
            "Action"   => "SendSms",
            "Version"  => "2017-05-25",
        ));

        $query = $this->mergePublicParamsAndSign($params);

        $http = $this->getHttp();
        try {
            $response = $http->request($this->baseUrl."?$query", 'GET', [
                'headers' => [
                    "x-sdk-client" => "php/2.0.0",
                ],
            ]);


            try {
                $contents = $http->parseJson($response);
                $this->checkAndThrow($contents);
            } catch (\Exception $exception) {
                \Log::error("阿里云短信:数据解析错误");
                \Log::warning($exception);

                return false;
            }


            return true;
        } catch (ClientException $exception) {
            \Log::error($exception);
            \Log::error($exception->getResponse()->getBody());
        }

        return false;
    }

    /**
     * 群发短信
     *
     * @param $mobiles         array 手机号
     * @param $smsSigns        array 短信签名
     * @param $smsTemplateCode string 模板号
     * @param $templateParams  string 模板参数
     * @return mixed
     */
    public function sendBatchSms($mobiles, $smsSigns, $smsTemplateCode, $templateParams)
    {
        $params = array ();

        // *** 需用户填写部分 ***


        //必填: 待发送手机号。支持JSON格式的批量调用，批量上限为100个手机号码,批量调用相对于单条调用及时性稍有延迟,验证码类型的短信推荐使用单条调用的方式
        $params["PhoneNumberJson"] = $mobiles;

        //必填: 短信签名，支持不同的号码发送不同的短信签名，每个签名都应严格按"签名名称"填写，请参考: https://dysms.console.aliyun.com/dysms.htm#/develop/sign
        $params["SignNameJson"] = $smsSigns;

        //必填: 短信模板Code，应严格按"模板CODE"填写, 请参考: https://dysms.console.aliyun.com/dysms.htm#/develop/template
        $params["TemplateCode"] = $smsTemplateCode;

        // 必填: 模板中的变量替换JSON串,如模板内容为"亲爱的${name},您的验证码为${code}"时,此处的值为
        // 友情提示:如果JSON中需要带换行符,请参照标准的JSON协议对换行符的要求,比如短信内容中包含\r\n的情况在JSON中需要表示成\\r\\n,否则会导致JSON在服务端解析失败
        $params["TemplateParamJson"] = $templateParams;


        //可选: 上行短信扩展码, 扩展码字段控制在7位或以下，无特殊需求用户请忽略此字段
        //$params["SmsUpExtendCodeJson"] = json_encode(array("90997","90998"));


        // *** 需用户填写部分结束, 以下代码若无必要无需更改 ***
        $params["TemplateParamJson"] = json_encode($params["TemplateParamJson"], JSON_UNESCAPED_UNICODE);
        $params["SignNameJson"] = json_encode($params["SignNameJson"], JSON_UNESCAPED_UNICODE);
        $params["PhoneNumberJson"] = json_encode($params["PhoneNumberJson"], JSON_UNESCAPED_UNICODE);

//        if(!empty($params["SmsUpExtendCodeJson"] && is_array($params["SmsUpExtendCodeJson"]))) {
//            $params["SmsUpExtendCodeJson"] = json_encode($params["SmsUpExtendCodeJson"], JSON_UNESCAPED_UNICODE);
//        }

        $params = array_merge($params, array (
            "RegionId" => "cn-hangzhou",
            "Action"   => "SendBatchSms",
            "Version"  => "2017-05-25",
        ));

        $query = $this->mergePublicParamsAndSign($params);

        $http = $this->getHttp();
        try {
            $response = $http->request($this->baseUrl."?$query", 'GET', [
                'headers' => [
                    "x-sdk-client" => "php/2.0.0",
                ],
            ]);


            try {
                $contents = $http->parseJson($response);
                $this->checkAndThrow($contents);
            } catch (ResourceException $resourceException) {
                throw $resourceException;
            } catch (\Exception $exception) {
                \Log::error("阿里云短信:数据解析错误");
                \Log::warning($exception);

                return false;
            }


            return true;
        } catch (ClientException $exception) {
            \Log::error($exception);
            \Log::error($exception->getResponse()->getBody());
        }

        return false;
    }

    /**
     * 查询发送状态
     *
     * @return mixed
     */
    public function querySendDetails()
    {
        // TODO: Implement querySendDetails() method.
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
        switch ($contents['Code']) {
            case "isv.BUSINESS_LIMIT_CONTROL":
                throw new ResourceException("一分钟内只能发送一条短信");
                break;
            default:
                \Log::error("短信发送失败,checkAndThrow");
                \Log::warning($contents);
                throw new ResourceException("短信发送失败:".$contents['Code'].",".$contents["Message"]);
                break;
        }

    }


}
