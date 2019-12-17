<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Middleware;


use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Exception\ResourceException;
use Symfony\Component\HttpKernel\Exception\PreconditionRequiredHttpException;

/**
 * api 接口请求专用
 *
 * Class RequestCheck
 *
 * @package Mallto\Tool\Middleware
 */
class RequestCheck
{
    protected $requestTypes = [
        'wechat'  => 'WECHAT',
        'android' => 'ANDROID',
        'ios'     => 'IOS',
        'web'     => 'WEB',
        'server'  => 'SERVER',
    ];

    /**
     * @param         $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $route = $request->route();

        //检查资源路由器上的id,
        //如:Route::resource("shop", 'ShopController'); ,请求shop/111时,如果111是非数字字符,则会走此校验
        $actionMethod = $route->getActionMethod();
        if ($actionMethod === 'show') {
            $firstRouterParam = Arr::first(($route->parameters()));
            if ($firstRouterParam) {
                if (!preg_match('/\d/', $firstRouterParam)) {
                    throw new ResourceException("无效的查询参数:".$firstRouterParam);
                }
            }
        }

        $uuid = SubjectUtils::getUUID();
        if (!$uuid) {
            throw new PreconditionRequiredHttpException(trans("errors.precondition_request"));
        }


        $user = Auth::guard("api")->user();
        //如果user存在,检查user和uuid是否一致
        if ($user) {
            $subject = $user->subject;
            if (!$subject->base) {
                $subject = $subject->baseSubject();
            }

            if ($subject->uuid != $uuid) {
                \Log::warning("当前请求用户不属于该uuid:".$subject->uuid.",".$uuid);
                throw new ResourceException("当前请求用户不属于该uuid");
            }
        }

//        $requestType = $request->header('REQUEST_TYPE');
//
//        if ($requestType && !in_array($requestType, $this->requestTypes)) {
//            throw new PreconditionFailedHttpException(trans("errors.precondition_failed"));
//        }

        $request->headers->set("mode", "api");

        return $next($request);
    }


}
