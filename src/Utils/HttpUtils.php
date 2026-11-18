<?php
/**
 * 版权所有 (c) 2018. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Utils;

/**
 * 由 PhpStorm 创建。
 * 作者： never615 <never615.com>
 * 日期： 2018/11/2
 * 时间： 下午5:35
 */
class  HttpUtils
{

    /**
     * 判断refere是否有权限访问
     *
     * @param $referer
     *
     * @return bool
     */
    public static function isAllowReferer($referer)
    {
        $refererHost = UrlUtils::getHost($referer);

        $allowDomainStr = config("app.allow_access_api_domain");
        $allowDomains = explode(",", $allowDomainStr);

        foreach ($allowDomains as $allowDomain) {
            if (starts_with($allowDomain, '*')) {
                if (ends_with($refererHost, substr($allowDomain, 2))) {
                    return true;
                }
            }
        }

        if (in_array($refererHost, $allowDomains)) {
            return true;
        }

        return false;
    }

}
