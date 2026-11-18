<?php
/*
 * 版权所有 (c) 2025. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Utils;

use Illuminate\Support\Str;

/**
 * 作者： never615 <never615.com>
 * 日期： 2025/5/14
 * 时间： 23:58
 */
class UserAgentUtils
{
    /**
     * 获取用户代理
     *
     * @return string
     */
    public static function getUserAgent()
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    /**
     * 判断是否是微信浏览器
     *
     * @return bool
     */
    public static function isWechatBrowser()
    {
        return strpos(self::getUserAgent(), 'MicroMessenger') !== false;
    }


    /**
     * 处理「getMobileSystemType」相关的内部协作步骤。
     */
    public static function getMobileSystemType($userAgent)
    {
        $userAgent = strtolower($userAgent);

        if (Str::contains($userAgent, ['android', 'okhttp'])) {
            $mobileType = 'android';
        } elseif (Str::contains($userAgent, ['iphone', 'darwin'])) {
            $mobileType = 'ios';
        }

        return $mobileType ?? 'other';
    }
}
