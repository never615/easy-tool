<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Middleware;


use Closure;
use Illuminate\Http\Request;
use Mallto\Admin\Data\Subject;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionRequiredHttpException;

/**
 *
 * @deprecated 废弃
 * 第三方 api 接口请求专用
 *
 * Class RequestCheck
 *
 * @package Mallto\Tool\Middleware
 */
class ThirdRequestCheck
{

    /**
     * @param         $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $headKeys = $request->headers->keys();

        $waitCheckHeaders = [
            "uuid",
            "app-id",
            "accept",
            "timestamp",
//            "sign-version",
        ];

        if (count(array_diff($headKeys, $waitCheckHeaders)) != (count($headKeys) - count($waitCheckHeaders))) {
            throw new PreconditionRequiredHttpException(trans("errors.precondition_request"));
        }

        $uuid = $request->header('UUID');

        $subject = Subject::where("uuid", $uuid)
            ->first();
        if (!$subject) {
            throw new PreconditionFailedHttpException(trans("errors.precondition_failed").":uuid");
        }


        return $next($request);
    }








}
