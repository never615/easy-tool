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


use Closure;
use Mallto\Tool\Data\AppSecret;
use Mallto\Tool\Exception\ResourceException;
use Mallto\Tool\Exception\SignException;
use Mallto\Tool\Exception\SubjectConfigException;
use Mallto\Tool\Utils\SignUtils;
use Mallto\Tool\Utils\SubjectUtils;

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
        $appId=$request->header("app_id");

        \Log::info("header app_id:".$appId);
        $appSecret=AppSecret::where("app_id",$appId)->first();
        if(!$appSecret){
            throw new SignException("app_id 无效");
        }

        if(is_null($appSecret->app_secret)){
            throw new ResourceException("未设置秘钥");
        }

        $key=$appSecret->app_secret;

        $inputs = $request->all();
        if (SignUtils::verifySign($inputs, $key)) {
            //pass
            return $next($request);
        } else {
            throw new SignException(trans("errors.sign_error"));
        }


    }
}
