<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Utils;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2018/11/2
 * Time: 下午5:35
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
        if(in_array(config('app.env'),['integration','local'])){
            return true;
        }
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

//        \Log::warning($refererHost);
//        \Log::warning($allowDomains);

        return false;
    }

}
