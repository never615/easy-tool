<?php
namespace Mallto\Tool\Utils;


use Illuminate\Support\Facades\Log;

/**
 * 工具类
 * 处理响应,主要是为了统一响应格式
 * Created by PhpStorm.
 * User: never615
 * Date: 05/11/2016
 * Time: 4:22 PM
 */
class ResponseUtils
{
    /**
     * 用来重载
     *
     * @param $name
     * @param $arguments
     * @return mixed
     */
    public static function __callStatic($name, $arguments)
    {
        $count = count($arguments);

        switch ($name) {
            case 'responseBasic':
                if (method_exists(ResponseUtils::class, $responseBasic = 'responseBasic'.$count)) {
                    return call_user_func_array(array (ResponseUtils::class, $responseBasic), $arguments);
                }
                break;
            case 'responseBasicByRedirect':
                if (method_exists(ResponseUtils::class, $responseBasicByRedirect = 'responseBasicByRedirect'.$count)) {
                    return call_user_func_array(array (ResponseUtils::class, $responseBasicByRedirect), $arguments);
                }
                break;
            case 'makeArr':
                if (method_exists(ResponseUtils::class, $makeArr = 'makeArr'.$count)) {
                    return call_user_func_array(array (ResponseUtils::class, $makeArr), $arguments);
                }
                break;
        }
    }


    /**
     * 基础的响应
     *
     * @param $code
     * @param $msg
     * @return \Illuminate\Http\JsonResponse
     */
    public static function responseBasic2($code, $msg)
    {
        return response()->json(['code' => $code, 'msg' => $msg]);
    }

    /**
     * 基础的响应
     *
     * @param $code
     * @param $msg
     * @param $data
     * @return \Illuminate\Http\JsonResponse
     */
    public static function responseBasic3($code, $msg, $data)
    {
        return response()->json(['code' => $code, 'msg' => $msg, 'data' => $data]);
    }

    /**
     * 重定向
     *
     * @param $url
     * @param $data
     * @return
     */
    public static function responseBasicByRedirect2($url, $data)
    {
        $url=urldecode($url);
        $ancho="";
        if (str_contains($url, "#")) {
            $ancho = mb_substr($url, strrpos($url, "#"));
            $url = str_replace($ancho, "", $url);
        }
        if (str_contains($url, "?")) {
            $url = $url."&".http_build_query($data).$ancho;
        } else {
            $url = $url.'?'.http_build_query($data).$ancho;
        }
        return redirect($url);
    }

    /**
     * 重定向
     *
     * @param $url
     * @param $code
     * @param $msg
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public static function responseBasicByRedirect3($url, $code, $msg)
    {
        return redirect($url.'?'.http_build_query(['code' => $code, 'msg' => $msg]));
    }

    /**
     * 重定向
     *
     * @param $url
     * @param $code
     * @param $msg
     * @param $data
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public static function responseBasicByRedirect4($url, $code, $msg, $data)
    {
        return redirect($url.'?'.http_build_query(['code' => $code, 'msg' => $msg, 'data' => $data]));
    }

    /**
     * 创建一个数组
     *
     * @param $code
     * @param $msg
     * @return array
     */
    public static function makeArr2($code, $msg)
    {
//        if($code=Constant::CODE_KEMAI){
//            $stre=explode(":", $msg);
//        }
        return ['code' => $code, 'msg' => $msg];
    }

    /**
     * 创建一个数组
     *
     * @param $code
     * @param $msg
     * @return array
     */
    public static function makeArr3($code, $msg, $data)
    {
        return ['code' => $code, 'msg' => $msg, 'data' => $data];
    }
}
