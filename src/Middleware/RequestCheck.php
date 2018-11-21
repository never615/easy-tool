<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Middleware;


use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Exception\ResourceException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
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
        //todo 优化
        $uuid = SubjectUtils::getUUID();
        $requestType = $request->header('REQUEST_TYPE');
        if (!$uuid) {
            throw new PreconditionRequiredHttpException(trans("errors.precondition_request"));
        }

        $user = Auth::guard("api")->user();
        //如果user存在,检查user和uuid是否一致
        if ($user) {
            $subject = $user->subject;
            if ($subject->uuid != $uuid) {
                throw new ResourceException("当前请求用户不属于该uuid");
            }
        }


        if ($requestType && !in_array($requestType, $this->requestTypes)) {
            throw new PreconditionFailedHttpException(trans("errors.precondition_failed"));
        }

        $request->headers->set("mode", "api");

        return $next($request);
    }


}
