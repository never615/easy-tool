<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

/**
 * referrer校验或签名校验
 *
 * 如果referrer不是墨兔的,则需要进行签名校验
 *
 * Created by PhpStorm.
 * User: never615
 * Date: 11/11/2016
 * Time: 8:16 PM
 */

namespace Mallto\Tool\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Mallto\Tool\Utils\HttpUtils;

/**
 * 签名校验,如果referre是墨兔域名则跳过签名校验
 * 用于接口同时提供给自己的项目使用也同时提供给第三方的情况
 *
 * Class AuthenticateSignWithReferrer
 *
 * @package Mallto\Tool\Middleware
 */
class AuthenticateSignWithReferrer
{

    use SignCheckTrait;

    protected $except = [
    ];


    /**
     * Handle an incoming request.
     *
     * @param         $request
     * @param Closure $next
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        //if(AppUtils::isTestEnv()){
        //    return $next($request);
        //}

        //如果请求方的Referer是自己的域名,则跳过检查
        $referer = $request->header('Referer');

        //兼容支付宝小程序
        if (Str::startsWith($referer, 'https://servicewechat.com') || Str::contains($referer, 'hybrid.alipay-eco.com')) {
            //allow_referer_appid
            $allow_referer_appid = config('other.allow_referer_appid');
            $allow_referer_appid = explode(',', $allow_referer_appid);

            //来自小程序的请求  wx71ea6eca62665ef9
            if ( ! empty($allow_referer_appid) && Str::contains($referer, $allow_referer_appid)) {
                return $next($request);
            }
        }

        if ( ! HttpUtils::isAllowReferer($referer)) {
            ////如果是来自管理端登录账号的请求,则跳过检查
            //$adminUser = null;
            //
            //try {
            //    $adminUser = Admin::user();
            //    if ( ! $adminUser && ! empty(config('auth.guards.admin_api'))) {
            //        $adminUser = Auth::guard("admin_api")->user();
            //        if ($adminUser) {
            //            return $next($request);
            //        }
            //    }
            //} catch (\Exception $exception) {
            //
            //}

            if ($request->userAgent()) {
                //临时兼容android部署工具 okhttp/3.14.4
                if ($request->userAgent() === 'okhttp/3.14.4') {
                    return $next($request);
                }
                //临时兼容android部署巡检工具  okhttp/3.12.3
                if (str_contains($request->userAgent(), 'okhttp')) {
                    $authorization = $request->header('authorization');
                    if ($authorization) {
                        return $next($request);
                    }
                }

                //if ($request->userAgent() === 'okhttp/3.12.3') {
                //    return $next($request);
                //}
            }

            return $this->check($request, $next);
        }

        return $next($request);
    }

}
