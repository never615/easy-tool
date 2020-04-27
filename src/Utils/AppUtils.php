<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Utils;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Mallto\Tool\Data\AppSecret;
use Mallto\Tool\Exception\ResourceException;
use RuntimeException;

/**
 * 工具类
 * Created by PhpStorm.
 * User: never615
 * Date: 05/11/2016
 * Time: 4:22 PM
 */
class AppUtils
{

    /**
     * 获取13位时间戳
     *
     * @return float
     */
    public static function getMicroTime()
    {
        return ceil(microtime(true) * 1000);
    }


    /**
     * 是否是测试环境
     * 测试环境是除了production,staging以外的所有环境
     *
     * @return bool
     */
    public static function isTestEnv()
    {
        if ( ! in_array(config("app.env"), [ "staging", "production" ])) {
            return true;
        } else {
            return false;
        }
    }


    /**
     * 是否是正式环境
     * @return bool
     */
    public static function isProduction()
    {
        return config('app.env') === 'production';
    }


    /**
     * 获取前端项目使用的url
     * 结尾不包含/
     *
     * @return string
     * @deprecated
     *
     */
    public static function h5Url()
    {
        $url = "";
        if (config("app.env") == "production" || config("app.env") == "staging") {
            $url = "https://h5.mall-to.com/";
        } else {
            $url = "https://h5-test.mall-to.com/";
        }

        return $url . config("app.env");
    }


    /**
     * 移除数组中的指定值的元素
     *
     * @param $originArr
     * @param $remove ,待移除的元素,可以是一个数据或者一个值
     *
     * @return mixed
     */
    public static function array_remove_value($originArr, $remove)
    {
        foreach ($originArr as $key => $value) {
            if (is_array($remove)) {
                if (in_array($value, $remove)) {
                    unset($originArr[$key]);
                }
            } else {
                if ($value == $remove) {
                    unset($originArr[$key]);
                    break;
                }
            }
        }

        return $originArr;
    }


    /**
     * 富文本转纯文本,图片显示成[图片]
     */
    public static function html2Text($content)
    {
        $content = preg_replace('/<img[^>]+>/i', '[图片]', $content);
        $content = preg_replace('/<[^>]+>/i', '', $content);

        return $content;
    }


    /**
     * 检查链接中的http协议,根据配置中的协议动态替换
     *
     * @param $url
     *
     * @return string
     * @deprecated
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
                $url = "https" . $url;

                return $url;
            }

            if ($httpProtocol === "http" && strpos($url, "https") === 0) {
                //如果要替换成的协议是http,而url是以https开头,替换掉
                $url = substr($url, 5);
                $url = "http" . $url;

                return $url;
            }

            return $url;

        }

        //如果url不是以http开头的不处理
        return $url;
    }


    /**
     * 转换格式
     *
     * @param $string
     *
     * @return mixed
     */
    public static function dateTransform($string)
    {
        $pa = '%<string.*?>(.*?)</string>%si';
        preg_match_all($pa, $string, $match);

        return str_replace(PHP_EOL, '', $match[1][0]);
    }


    /**
     * 检查字符串是否有中文
     *
     * @param $str
     *
     * @return bool
     */
    public static function hasChinese($str)
    {
//        if (preg_match("/^[".chr(0xa1)."-".chr(0xff)."]+$/", $str)) { //只能在GB2312情况下使用
//        if (preg_match("/^[\x7f-\xff]+$/", $str)) { //兼容gb2312,utf-8  //判断字符串是否全是中文
        if (preg_match("/[\x7f-\xff]/", $str)) {  //判断字符串中是否有中文
            return true;
        } else {
            return false;
        }

    }


    /**
     * PHP - 通用唯一识别码（UUID）的生成
     *
     * 简单的说 UUID 就是一串全球唯一的(16进制)数字串。
     * UUID 的全拼为“Universally Unique Identifier”，可以译为“通用唯一识别码”。UUID 由开源软件基金会 (Open Software Foundation, OSF)
     * 定义，是分布式计算环境 (Distributed Computing Environment, DCE) 的一个组成部分。 UUID
     * 的标准格式为“xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxx”，五个部分分别为8个字符、4个字符、4个字符、4个字符、12个字符，中间用“-”号间隔。常见的
     * GUID(Globally Unique Identifier)是微软对 UUID 标准的一种实现。
     *
     * 原文出自：www.hangge.com  转载请保留原文链接：http://www.hangge.com/blog/cache/detail_1528.html
     *
     * @param string $prefix
     *
     * @return string
     */
    public static function create_uuid($prefix = "")
    {
        $str = md5(uniqid(mt_rand(), true));
        $uuid = substr($str, 0, 8) . '-';
        $uuid .= substr($str, 8, 4) . '-';
        $uuid .= substr($str, 12, 4) . '-';
        $uuid .= substr($str, 16, 4) . '-';
        $uuid .= substr($str, 20, 12);

        return $prefix . $uuid;
    }


    /**
     * 获取随机字符串
     *
     * @param int $length
     *
     * @return bool|string
     */
    public static function getRandomString($length = 42)
    {
        /*
         * Use OpenSSL (if available)
         */
        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes($length * 2);

            if ($bytes === false) {
                throw new RuntimeException('Unable to generate a random string');
            }

            return substr(str_replace([ '/', '+', '=' ], '', base64_encode($bytes)), 0, $length);
        }

        $pool = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

        return substr(str_shuffle(str_repeat($pool, 5)), 0, $length);
    }


    /**
     * 将stdClass Object转换成array格式
     *
     * @param  $array ,需要转换的对象
     *
     * @return array
     */
    public static function object2array($array)
    {
        if (is_object($array)) {
            $array = (array) $array;
        }
        if (is_array($array)) {
            foreach ($array as $key => $value) {
                $array[$key] = self::object2array($value);
            }
        }

        return $array;
    }


    /**
     * 提取富文本中的文字字符
     *
     * @param $text
     *
     * @return string
     */
    public static function htmlFilter($text)
    {
        $content_02 = htmlspecialchars_decode($text);//把一些预定义的 HTML 实体转换为字符
        $content_03 = str_replace("&nbsp;", "", $content_02);//将空格替换成空
        $contents = strip_tags($content_03);//函数剥去字符串中的 HTML、XML 以及 PHP 的标签,获取纯文本内容

        return $contents;
    }


    /**
     * 获取域名后的第一段路径
     *
     * 比如:http://mall.mall-to.com/test/test1/test2  number=0,获取test
     *
     * @param $request
     * @param $number ,获取路径的第几段信息
     *
     * @return mixed
     * @deprecated
     */
    public static function getPathKey($request, $number = 0)
    {
        $path = $request->path();
        $pathArr = explode("/", $path);

        return $pathArr[$number];
    }


    /**
     * 获取指定的header
     *
     * @param      $headerKey
     * @param bool $low
     *
     * @return mixed|null
     * @deprecated
     */
    public static function getHeader($headerKey, $low = false)
    {
        return \Illuminate\Support\Facades\Request::header($headerKey);
    }


    /**
     * http_build_query()函数的反操作
     *
     * @param $data
     *
     * @return array
     */
    public static function httpQueryBuildReverse($data)
    {
        try {
            if ($data) {
                $decodeData = urldecode($data);
                $result = [];
                $queries = explode("&", $decodeData);
                foreach ($queries as $query) {
                    $subQuery = explode("=", $query);
                    if (count($subQuery) != 2) {
                        return [];
                    }
                    $result[$subQuery[0]] = $subQuery[1];
                }

                return $result;
            } else {
                return $data;
            }
        } catch (\Exception $e) {
            \Log::warning($e);
            \Log::warning($data);

            return [];
        }
    }


    /**
     *   将数组转换为xml
     *
     * @param array $data 要转换的数组
     * @param bool  $root 是否要根节点
     *
     * @return string         xml字符串
     * @author Dragondean
     * @url    http://www.cnblogs.com/dragondean
     */
    public static function arr2xml($data, $root = true)
    {
        $str = "";
        if ($root) {
            $str .= "<xml>";
        }
        foreach ($data as $key => $val) {
            //去掉key中的下标[]
            $key = preg_replace('/\[\d*\]/', '', $key);
            if (is_array($val)) {
                $child = self::arr2xml($val, false);
                $str .= "<$key>$child</$key>";
            } else {
                $str .= "<$key><![CDATA[$val]]></$key>";
            }
        }
        if ($root) {
            $str .= "</xml>";
        }

        return $str;
    }


    /**
     * 根据numbers 返回天数数组,如:
     * $numbers 为 3,返回
     * [
     *   "第一天","第二天","第三天"
     * ]
     *
     * @param $numbers
     *
     * @return array
     */
    public static function daysGenerator($numbers)
    {
        $dayArr = [];

        for ($i = 1; $i <= $numbers; $i++) {
            $dayArr[] = "第" . $i . "天";
        }

        return $dayArr;
    }


    /**
     * 获取秘钥信息
     *
     * @return AppSecret
     */
    public static function getAppSecret()
    {
        $appId = request()->header("app_id");

        $appSecret = AppSecret::where("app_id",
            $appId)->first();

        if ( ! $appSecret) {
            throw new ResourceException("无效的app id:" . $appId);
        }

        return $appSecret;
    }


    /**
     * 解析openid
     *
     * @param $openid
     *
     * @return mixed|string
     * @throws AuthenticationException
     */
    public static function decryptOpenid($openid)
    {
        if (empty($openid)) {
            throw new AuthenticationException('openid为空,请检查微信授权或刷新重试');
        }

        try {
            $openid = urldecode($openid);
            $openid = decrypt($openid);

            return $openid;
        } catch (DecryptException $e) {
            \Log::warning("解析openid失败");
            \Log::warning($openid);
            throw new AuthenticationException('openid解析失败,请检查微信授权或刷新重试');
            //throw new ResourceException("openid无效");
        }
    }


    /**
     * 从原始数据中获取openid
     *
     * @param $orginalOpenid
     *
     * @return mixed
     * @throws AuthenticationException
     */
    public static function getOpenidFromOriginalOpenid($orginalOpenid)
    {
        $openids = self::getOpenidFromOriginalOpenids($orginalOpenid);

        return $openids[0];
    }


    /**
     * 从原始数据中获取openid数据和时间戳
     *
     * @param $orginalOpenid
     *
     * @return array
     * @throws AuthenticationException
     */
    public static function getOpenidFromOriginalOpenids($orginalOpenid)
    {
        $openid = self::decryptOpenid($orginalOpenid);

        //数组有两个元素,第一个就是原始openid;第二个就是时间戳
        return explode('|||', $openid);
    }

}
