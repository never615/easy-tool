<?php
/*
 * Copyright (c) 2025. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Utils;

/**
 * User: never615 <never615.com>
 * Date: 2025/5/14
 * Time: 23:58
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


    public static function getMobileSystemType($userAgent)
    {
        $userAgent = strtolower($userAgent);

        if (str_contains($userAgent, ['android', 'okhttp'])) {
            $mobileType = 'android';
        } elseif (str_contains($userAgent, ['iphone', 'darwin'])) {
            $mobileType = 'ios';
        }

        return $mobileType ?? 'other';
    }
}