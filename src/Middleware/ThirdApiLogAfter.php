<?php

namespace Mallto\Tool\Middleware;

use Encore\Admin\AppUtils;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Mallto\Tool\Domain\Log\Logger;

/**
 * 向第三方提供的接口通讯日志记录
 * Class ThirdApiLog
 *
 * @package Encore\Admin\Middleware
 */
class ThirdApiLogAfter
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
        $response = $next($request);

        $log = [
            'path'   => $request->path(),
            'method' => $request->method(),
            'request_ip'     => $request->header("X-Forwarded-For"),
            'input'  => $response->getContent(),
            'uuid'   => AppUtils::getUUID(),
        ];
        $logger = resolve(Logger::class);
        $logger->logOwnerApi("响应", $log);

        return $response;
    }
}
