<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Sms;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2018/8/1
 * Time: 下午7:50
 */
interface Sms
{

    /**
     * 发送单条短信
     *
     * @param $mobile          string 手机号
     * @param $smsSign         string 短信签名
     * @param $smsTemplateCode string 模板号
     * @param $templateParam   array 模板参数
     * @param $content         string 短信内容
     *
     * @return mixed
     */
    public function sendSms(
        $mobile,
        $smsTemplateCode,
        $templateParam,
        $smsSign = null,
        $content = null
    );


    /**
     * 群发短信
     *
     * 一次手机号上限是100
     *
     *
     * @param $mobiles         array 手机号
     * @param $smsSigns        array 短信签名
     * @param $smsTemplateCode string 模板号
     * @param $templateParams  string 模板参数
     *
     * @return mixed
     */
    public function sendBatchSms($mobiles, $smsSigns, $smsTemplateCode, $templateParams);


    /**
     * 查询发送状态
     *
     * @return mixed
     */
    public function querySendDetails();
}
