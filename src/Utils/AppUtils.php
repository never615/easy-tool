<?php
namespace Mallto\Tool\Utils;


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
     * 移除数组中的指定值的元素
     *
     * @param $originArr
     * @param $remove ,待移除的元素,可以是一个数据或者一个值
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
     * 生成核销码
     * 核销码生成规则  加10位时间戳+(用户id,补足6位);
     *
     * @param $userId
     * @return string
     */
    public static function generateVerifyCode($userId)
    {
        return time().sprintf('%06d', $userId);
    }


    /**
     * 获取指定的header
     *
     * @param      $headerKey
     * @param bool $low
     * @return mixed|null
     */
    public static function getHeader($headerKey, $low = false)
    {
        return \Illuminate\Support\Facades\Request::header($headerKey);
//        $headers = array ();
//        foreach ($_SERVER as $key => $value) {
//            if ('HTTP_' == substr($key, 0, 5)) {
//                if ($low) {
//                    $key = strtolower($key);
//                    $value = strtolower($value);
//                }
//                $headers[str_replace('_', '-', substr($key, 5))] = $value;
//            }
//        }
//
//        if (isset($headers[$headerKey])) {
//            return $headers[$headerKey];
//        } else {
//            return null;
//        }
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
     * @return string
     */
    public static function checkHttpProtocol($url)
    {

        if (strpos($url, "http") === 0) {
            $httpProtocol = config("app.http_protocol");
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
     * 转换格式
     *
     * @param $string
     * @return mixed
     */
    public static function dateTransform($string)
    {
        $pa = '%<string.*?>(.*?)</string>%si';
        preg_match_all($pa, $string, $match);

        return str_replace(PHP_EOL, '', $match[1][0]);
    }

}
