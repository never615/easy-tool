<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Utils;

use Mallto\Tool\Exception\PermissionDeniedException;


/**
 * 工具类
 * Created by PhpStorm.
 * User: never615
 * Date: 05/11/2016
 * Time: 4:22 PM
 */
class UrlUtils
{


    /**
     * 获取域名后的第一段路径
     *
     * 比如:http://mall.mall-to.com/test/test1/test2  number=0,获取test
     *
     * @param $request
     * @param $number ,获取路径的第几段信息
     * @return mixed
     */
    public static function getPathKey($request, $number = 0)
    {
        $path = $request->path();
        $pathArr = explode("/", $path);

        return $pathArr[$number];
    }


    /**
     * 获取 host 只返回域名
     *
     * 如:https://xxx.mall-to.com:8080 返回xxx.mall-to.com
     *
     * @param $url
     * @return bool
     */
    public static function getHost($url)
    {
        $tempu = parse_url($url);

        return $tempu['host'] ?? "";

//        $urlArr1 = explode("//", $url);
//        if (count($urlArr1) > 1) {
//            $urlArr = explode("/", $urlArr1[1]);
//        } else {
//            $urlArr = explode("/", $url);
//        }
//
//        return isset($urlArr[0]) ? $urlArr[0] : false;
    }


    /**
     * 获取 domian 包含端口和http协议
     *
     * @param $url
     * @return bool
     */
    public static function getDomain($url)
    {
        $urlArr1 = explode("//", $url);
        $scheme="";
        if (count($urlArr1) > 1) {
            $urlArr = explode("/", $urlArr1[1]);
            $scheme=$urlArr1[0]."//";
        } else {
            $urlArr = explode("/", $url);
        }

        return isset($urlArr[0]) ? $scheme.$urlArr[0] : false;
    }






    /**
     * 检查链接中的http协议,根据配置中的协议动态替换
     *
     * @param $url
     * @return string
     */
    public static function checkHttpProtocol($url)
    {

        if (strpos($url, "http") === 0) {
//            $httpProtocol = config("app.http_protocol", 'https');
            $secure = config("admin.https");
            if ($secure) {
                $httpProtocol = "https";
            } else {
                $httpProtocol = "http";
            }
            if ($httpProtocol === "https" && strpos($url, "https") !== 0) {
                //如果要换成的协议是https,而url不是以https开头,替换掉
                $url = substr($url, 4);
                $url = "https".$url;

                return $url;
            }

            if ($httpProtocol === "http" && strpos($url, "https") === 0) {
                //如果要替换成的协议是http,而url是以https开头,替换掉
                $url = substr($url, 5);
                $url = "http".$url;

                return $url;
            }

            return $url;

        } else {
            //如果url不是以http开头的不处理
            return $url;
        }
    }


    /**
     * 检查域名是否符合要求
     *
     * @param string $url     请求来的地址
     * @param array  $domains 授权回调可信域名
     */
    public static function checkDomainOAuth($url, $domains = null)
    {
        if (!$domains) {
            $callbackDomain = config("app.oauth_callback_domain");
            $domains = explode(",", $callbackDomain);
        }

        $requestDomain = UrlUtils::getHost($url);
        $isAuth = false;

        //先检查有没有*号开头的域名
        foreach ($domains as $domain) {
            if (starts_with($domain, "*.")) {
                $domain = str_replace("*.", "", $domain);
                if (ends_with($requestDomain, $domain)) {
                    $isAuth = true;
                    break;
                }
            }
        }

        if ($isAuth & in_array($requestDomain, $domains)) {
            $isAuth = true;
        }

        if (!$isAuth) {
            throw new PermissionDeniedException("回调域名不可信:".$requestDomain);
        }
    }

}
