<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Middleware;

use Illuminate\Http\Request;
use Mallto\Tool\Domain\Log\Logger;

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
        $ip = "";
        $tempIp = $request->header("X-Forwarded-For");
        if ($tempIp) {
            $ip = $tempIp;
        }
//        else {
//            $ip = $request->getClientIp();
//        }

        $log = [
            'action'     => "请求",
            'method'     => $request->method(),
            'url'        => $request->fullUrl(),
            'request_ip' => $ip,
            'input'      => json_encode($request->all(), JSON_UNESCAPED_UNICODE),
            'header'     => json_encode($request->headers->all(), JSON_UNESCAPED_UNICODE),
        ];

        $logger = resolve(Logger::class);
        $logger->logOwnerApi($log);

        $response = $next($request);

        $ip = "";
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
            'input'      => $response->getContent(),
            'status'     => $response->getStatusCode(),
        ];
        $logger = resolve(Logger::class);
        $logger->logOwnerApi($log);

        return $response;
    }
}
