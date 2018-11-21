<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Jobs\LogJob;

/**
 * 向第三方提供的接口通讯日志记录
 * 记录的都是自己的接口
 * Class ThirdApiLog
 *
 * @package Encore\Admin\Middleware
 */
class OwnerApiLog
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     * @return mixed
     */
    public function handle(Request $request, \Closure $next)
    {
        $ip = 0;
        $tempIp = $request->header("X-Forwarded-For");
        if ($tempIp) {
            $ip = $tempIp;
        }


        $user = Auth::guard("api")->user();
        $userId = $user ? $user->id : 0;

        $uuid = SubjectUtils::getUUIDNoException() ?: 0;


        $log = [
            'action'     => "请求",
            'method'     => $request->method(),
            'url'        => $request->fullUrl(),
            'request_ip' => $ip,
            'user_id'    => $userId,
            'input'      => json_encode($request->all(), JSON_UNESCAPED_UNICODE),
            'header'     => json_encode($request->headers->all(), JSON_UNESCAPED_UNICODE),
            "uuid"       => $uuid,
        ];


        dispatch(new LogJob("logOwnerApi", $log));

        $response = $next($request);

        $tempIp = $request->header("X-Forwarded-For");
        if ($tempIp) {
            $ip = $tempIp;
        } else {
            $ip = $request->getClientIp();
        }
        $log = [
            'action'     => "响应",
            'method'     => $request->method(),
            'url'        => $request->fullUrl(),
            'request_ip' => $ip,
            'user_id'    => $userId,
            'input'      => is_array($response->getContent()) ? $response->getContent() :
                (is_string($response->getContent()) ? $response->getContent() : "异常数据"),
            'status'     => $response->getStatusCode(),
            "uuid"       => $uuid,
        ];


        dispatch(new LogJob("logOwnerApi", $log));

        return $response;
    }
}
