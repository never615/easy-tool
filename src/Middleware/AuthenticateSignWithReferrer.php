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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Mallto\Tool\Data\AppSecret;
use Mallto\Tool\Exception\PermissionDeniedException;
use Mallto\Tool\Exception\ResourceException;
use Mallto\Tool\Exception\SignException;
use Mallto\Tool\Utils\HttpUtils;
use Mallto\Tool\Utils\SignUtils;
use Symfony\Component\HttpKernel\Exception\PreconditionRequiredHttpException;

/**
 * 签名校验
 * Class AuthenticateSignWithReferrer
 *
 * @package Mallto\Tool\Middleware
 */
class AuthenticateSignWithReferrer
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
    public function handle(Request $request, Closure $next)
    {
        //如果请求方的Referer是自己的域名,则跳过检查
        $referer = $request->header('Referer');


        if (!HttpUtils::isAllowReferer($referer)) {
            return $this->check($request, $next);
        }

        return $next($request);
    }

    protected function check(Request $request, Closure $next)
    {
        $appId = $request->header("app_id");
        $appSecret = AppSecret::where("app_id", $appId)->first();

        if (!$appSecret || !$appSecret->switch) {
            \Log::warning("app_id 无效:".$appId);
            \Log::warning($request->url());
            throw new SignException("app_id 无效");
        }

        if (is_null($appSecret->app_secret)) {
            throw new ResourceException("未设置秘钥");
        }

        $secret = $appSecret->app_secret;

        $inputs = $request->all();

        $signVersion = $request->header('signature_version');
        if ($signVersion === null) {
            $signVersion = $request->header('sign_version', "1");
        }

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

                if (!$timestamp | !$uuid | !$appId) {
                    throw new PreconditionRequiredHttpException(trans("errors.precondition_request"));
                }


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
            case "4":  //签名校验+时间戳校验,请求头中的appid,uuid,和timestamp需要参与到签名中
                $timestamp = $request->header("timestamp");
                $uuid = $request->header("uuid");
                $appId = $request->header("app_id");
                $signatureNonce = $request->header("signature_nonce");
                $signature = $request->header("signature");

                if (is_null($timestamp) || is_null($uuid) || is_null($appId) ||
                    is_null($signatureNonce) || is_null($signature)) {
                    throw new PreconditionRequiredHttpException(trans("errors.precondition_request"));
                }


                //时间戳格式检查
                if (!Carbon::hasFormat($timestamp, "Y-m-d H:i:s")) {
                    throw new ResourceException("InvalidTimeStamp.Format");
                }

                //随机字符串15分钟内是否使用过,同一个uuid,app_id,同一个接口,同一个随机字符串是否重复

                $requestPath = $request->path();

                $nonce = $uuid.$appId.$requestPath.$signatureNonce;
                if (Cache::get($nonce)) {
                    throw new ResourceException("请求已被接受,signature_nonce:".$signatureNonce);
                }


                if (Carbon::now()->subMinutes(15) < $timestamp) {
                    //和当前时间间隔比较在15分钟内
                    //检查签名
                    if (SignUtils::verifySign4(array_merge($inputs, [
                        "timestamp"       => $timestamp,
                        "uuid"            => $uuid,
                        "app_id"          => $appId,
                        "signature_nonce" => $signatureNonce,
                        "signature"       => $signature,
                    ]), $secret)) {
                        //pass
                        Cache::put($nonce, 1, 15 * 60);

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
