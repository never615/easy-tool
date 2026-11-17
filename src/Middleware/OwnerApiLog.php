<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Jobs\LogJob;
use Mallto\Tool\Domain\Log\OwnerApiLogFilter;
use Mallto\Tool\Domain\Log\OwnerApiLogPayload;
use Mallto\Tool\Domain\Log\OwnerApiLogSettings;
use Mallto\Tool\Utils\AppUtils;
use Illuminate\Support\Facades\Log;

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
            //if ( ! $this->shouldLogOperation($request) && ! AppUtils::isTestEnv()) {
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
        $payload = new OwnerApiLogPayload();

        $log = [
            'action'     => '请求',
            'method'     => $request->method(),
            'url'        => $payload->url($request->fullUrl()),
            'request_ip' => $ip,
            'user_id'    => $userId,
            'input'      => $payload->json($request->all()),
            'header'     => $payload->json($request->headers->all()),
            'uuid'       => $uuid,
            'request_id' => $requestId,
        ];

        if (config('app.log.dispatch_now')) {
            dispatch_now(new LogJob('logOwnerApi', $log));
        } else {
            dispatch(new LogJob('logOwnerApi', $log));
        }

        $request->headers->set('request-id', $requestId);

        $response = $next($request);

        if (is_array($response->getContent())) {
            $input = $payload->json($response->getContent());
        } else {
            if (is_string($response->getContent())) {
                try {
                    //也是为了防止图片响应异常
                    $input = json_decode($response->getContent(), true);
                    if (is_null($input)) {
                        $input = '非json数据';
                    } else {
                        $input = is_array($input)
                            ? $payload->json($input)
                            : $payload->text($response->getContent());
                    }
                } catch (\Exception $exception) {
                    Log::warning($exception);
                    $input = '异常数据';
                }
            } else {
                $input = '其他数据';
            }
        }

        $log = [
            'action'     => '响应',
            'method'     => $request->method(),
            'url'        => $payload->url($request->fullUrl()),
            'request_ip' => $ip,
            'user_id'    => $userId,
            'input'      => $input,
            'status'     => $response->getStatusCode(),
            'uuid'       => $uuid,
            'request_id' => $requestId,
        ];

        $response->headers->set('request-id', $requestId);

        if (config('app.log.dispatch_now')) {
            dispatch_now(new LogJob('logOwnerApi', $log));
        } else {
            dispatch(new LogJob('logOwnerApi', $log));
        }

        return $response;
    }


    /**
     * @param Request $request
     *
     * @return bool
     */
    protected function shouldLogOperation(Request $request)
    {

        // 高频接口在最外层只做本地路径匹配，禁止为日志规则访问 Redis / PostgreSQL。
        $filter = new OwnerApiLogFilter();
        if ($filter->matches($request, (array) config('owner_api_log.hard_exclude', []))) {
            return false;
        }

        $settings = app(OwnerApiLogSettings::class)->forRequest();
        if (!$settings['enabled']) {
            return false;
        }

        return $filter->matches($request, $settings['include']);
    }

}
