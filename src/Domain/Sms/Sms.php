<?php
/**
 * 版权所有 (c) 2018. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Domain\Sms;

/**
 * 由 PhpStorm 创建。
 * 作者： never615 <never615.com>
 * 日期： 2018/8/1
 * 时间： 下午7:50
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
