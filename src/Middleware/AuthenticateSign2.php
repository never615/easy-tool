<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

/**
 * 签名校验
 *
 * Created by PhpStorm.
 * User: never615
 * Date: 11/11/2016
 * Time: 8:16 PM
 */

namespace Mallto\Tool\Middleware;


use Carbon\Carbon;
use Closure;
use Mallto\Tool\Data\AppSecret;
use Mallto\Tool\Exception\PermissionDeniedException;
use Mallto\Tool\Exception\ResourceException;
use Mallto\Tool\Exception\SignException;
use Mallto\Tool\Utils\SignUtils;

/**
 * 管理端权限过滤
 *
 * 使用appid和secret
 *
 * Class AuthenticateAdmin
 *
 * @package App\Http\Middleware
 */
class AuthenticateSign2
{
    protected $except = [
    ];

    /**
     * Handle an incoming request.
     *
     * @param         $request
     * @param Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $appId = $request->header("app_id");

//        \Log::info("header app_id:".$appId);
        $appSecret = AppSecret::where("app_id", $appId)->first();


        if (!$appSecret || !$appSecret->switch) {
            \Log::warning("app_id 无效:".$appId);
            throw new SignException("app_id 无效");
        }

        if (is_null($appSecret->app_secret)) {
            throw new ResourceException("未设置秘钥");
        }

        $secret = $appSecret->app_secret;

        $inputs = $request->all();

        $signVersion = $request->header('sign_version', "1");

        switch ($signVersion) {
            case "999":  //用户测试环境,直接通过校验
                if (config("app.env") == "production" || config("app.env") == "staging") {
                    throw new PermissionDeniedException("无效的签名版本");
                } else {
                    return $next($request);
                }
                break;
            case "1": //原始版本,只有签名校验
                if (SignUtils::verifySign($inputs, $secret)) {
                    //pass
                    return $next($request);
                } else {
                    throw new SignException(trans("errors.sign_error"));
                }
                break;
            case "2":  //签名校验+时间戳校验
                $timestamp = $request->header("timestamp");
                //时间戳格式检查
                if (!Carbon::hasFormat($timestamp, "Y-m-d H:i:s")) {
                    throw new ResourceException("InvalidTimeStamp.Format");
                }


                if (Carbon::now()->subMinutes(15) < $timestamp) {
                    //和当前时间间隔比较在15分钟内
                    //检查签名
                    if (SignUtils::verifySign2($inputs, $secret)) {
                        //pass
                        return $next($request);
                    } else {
                        throw new SignException(trans("errors.sign_error"));
                    }
                } else {
                    throw new ResourceException("InvalidTimeStamp.Expired");
                }
                break;
            case "3":  //签名校验+时间戳校验,请求头中的appid,uuid,和timestamp需要参与到签名中
                $timestamp = $request->header("timestamp");
                $uuid = $request->header("uuid");
                $appId = $request->header("app_id");


                //时间戳格式检查
                if (!Carbon::hasFormat($timestamp, "Y-m-d H:i:s")) {
                    throw new ResourceException("InvalidTimeStamp.Format");
                }

                if (Carbon::now()->subMinutes(15) < $timestamp) {
                    //和当前时间间隔比较在15分钟内
                    //检查签名
                    if (SignUtils::verifySign2(array_merge($inputs, [
                        "timestamp" => $timestamp,
                        "uuid"      => $uuid,
                        "app_id"    => $appId,
                    ]), $secret)) {
                        //pass
                        return $next($request);
                    } else {
                        throw new SignException(trans("errors.sign_error"));
                    }
                } else {
                    throw new ResourceException("InvalidTimeStamp.Expired");
                }
                break;
            default:
                throw new PermissionDeniedException("无效的签名版本");
                break;
        }


    }
}
