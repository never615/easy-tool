<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Wechat;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2019/1/7
 * Time: 8:00 PM
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
        return array_merge(config("other.wechat_template_id.admin_system"),
            config("other.wechat_template_id.user_system"));
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
        return array_key_exists($templateId, config("other.wechat_template_id.user_system"));
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
        return array_key_exists($templateId, config("other.wechat_template_id.admin_system"));
    }

}
