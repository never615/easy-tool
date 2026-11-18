<?php
/**
 * 版权所有 (c) 2019. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Domain\Wechat;

/**
 * 由 PhpStorm 创建。
 * 作者： never615 <never615.com>
 * 日期： 2019/1/7
 * 时间： 8:00 下午
 */
class WechatUtils
{

    /**
     * 获取微信模板id
     *
     * @return array
     */
    public static function getTemplateIds()
    {
        return array_merge(config("wechat-template.wechat_template_id.admin_system"),
            config("wechat-template.wechat_template_id.user_system"));
    }


    /**
     * 是否是用户系统的模板id
     *
     * @param $templateId
     *
     * @return bool
     */
    public static function isUserSystemTemplate($templateId)
    {
        return array_key_exists($templateId, config("wechat-template.wechat_template_id.user_system"));
    }


    /**
     * 是否是管理系统的模板id
     *
     * @param $templateId
     *
     * @return bool
     */
    public static function isAdminSystemTemplate($templateId)
    {
        return array_key_exists($templateId, config("wechat-template.wechat_template_id.admin_system"));
    }

}
