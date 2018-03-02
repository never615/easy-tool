<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Middleware;


use Illuminate\Http\Request;
use Mallto\Tool\Domain\Log\Logger;
use Mallto\Tool\Utils\SubjectUtils;

/**
 * 向第三方提供的接口通讯日志记录
 * Class ThirdApiLog
 *
 * @package Encore\Admin\Middleware
 */
class ThirdApiLogBefore
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
        } else {
            $ip = $request->getClientIp();
        }


        $log = [
            'path'       => $request->path(),
            'method'     => $request->method(),
            'request_ip' => $ip,
            'input'      => json_encode($request->all(),JSON_UNESCAPED_UNICODE),
            'uuid'       => SubjectUtils::getUUID(),
            'header'     => json_encode($request->headers->all(),JSON_UNESCAPED_UNICODE),
        ];

        $logger = resolve(Logger::class);
        $logger->logOwnerApi("请求", $log);

        return $next($request);
    }
}
