<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Jobs\LogJob;
use Mallto\Tool\Utils\AppUtils;

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
     *
     * @return mixed
     */
    public function handle(Request $request, \Closure $next)
    {
        if ( ! $this->shouldLogOperation($request)) {
            return $next($request);
        }

        //检查path
        $basePath = $request->getBasePath();
        $ip = 0;
        $tempIp = $request->header('X-Forwarded-For');
        if ($tempIp) {
            $ip = $tempIp;
        }

        $userId = 0;
        if (config('auth.guards.api.provider')) {
            $user = Auth::guard('api')->user();
            $userId = $user ? $user->id : 0;
        }

        $uuid = SubjectUtils::getUUIDNoException() ?: 0;

        $requestId = AppUtils::create_uuid();

        $log = [
            'action'     => '请求',
            'method'     => $request->method(),
            'url'        => $request->fullUrl(),
            'request_ip' => $ip,
            'user_id'    => $userId,
            'input'      => json_encode($request->all(), JSON_UNESCAPED_UNICODE),
            'header'     => json_encode($request->headers->all(), JSON_UNESCAPED_UNICODE),
            'uuid'       => $uuid,
            'request_id' => $requestId,
        ];

        dispatch(new LogJob('logOwnerApi', $log));

        $request->headers->set('request-id', $requestId);

        $response = $next($request);

        if (is_array($response->getContent())) {
            $input = json_encode($response->getContent());
        } else {
            if (is_string($response->getContent())) {
                try {
                    //也是为了防止图片响应异常
                    $input = json_decode($response->getContent());
                    if (is_null($input)) {
                        $input = '非json数据';
                    } else {
                        $input = $response->getContent();
                    }
                } catch (\Exception $exception) {
                    \Log::warning($exception);
                    $input = '异常数据';
                }
            } else {
                $input = '其他数据';
            }
        }

        $log = [
            'action'     => '响应',
            'method'     => $request->method(),
            'url'        => $request->fullUrl(),
            'request_ip' => $ip,
            'user_id'    => $userId,
            'input'      => $input,
            'status'     => $response->getStatusCode(),
            'uuid'       => $uuid,
            'request_id' => $requestId,
        ];

        $response->headers->set('request-id', $requestId);

        dispatch(new LogJob('logOwnerApi', $log));

        return $response;
    }


    /**
     * @param Request $request
     *
     * @return bool
     */
    protected function shouldLogOperation(Request $request)
    {
        return config('app.log.owner_api')
            && ! $this->inExceptArray($request);
    }


    /**
     * Determine if the request has a URI that should pass through CSRF verification.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return bool
     */
    protected function inExceptArray($request)
    {
        $excepts = config('app.log.except') ?? [];
        foreach ($excepts as $except) {
            if ($except !== '/') {
                $except = trim($except, '/');
            }

            $methods = [];

            if (Str::contains($except, ':')) {
                [ $methods, $except ] = explode(':', $except);
                $methods = explode(',', $methods);
            }

            $methods = array_map('strtoupper', $methods);

            if ($request->is($except) &&
                (empty($methods) || in_array($request->method(), $methods))) {
                return true;
            }
        }

        return false;
    }
}
