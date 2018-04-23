<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Middleware;


use Closure;
use Illuminate\Http\Request;
use Mallto\Admin\SubjectUtils;
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

    /**
     * @param         $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {

        $uuid = SubjectUtils::getUUID();
        $requestType = $request->header('REQUEST_TYPE');
        if (!$uuid) {
//            if (!$requestType || !$uuid) {
            throw new PreconditionRequiredHttpException(trans("errors.precondition_request"));
        }


        if ($requestType&&!in_array($requestType, config('mall.request_type'))) {
            throw new PreconditionFailedHttpException(trans("errors.precondition_failed"));
        }

        $request->headers->set("mode", "api");

        return $next($request);
    }


}
