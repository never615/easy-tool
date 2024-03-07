<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Mallto\Admin\SubjectConfigConstants;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Exception\HttpException;
use Mallto\Tool\Exception\ResourceException;
use Mallto\Tool\Utils\AppUtils;
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

    //protected $requestTypes = [
    //    'WECHAT',
    //    'ANDROID',
    //    'IOS',
    //    'WEB',
    //    'SERVER',
    //];

    /**
     * @param         $request
     * @param Closure $next
     *
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
                if ( ! preg_match('/\d/', $firstRouterParam)) {
                    //throw new ResourceException("无效的查询参数:" . $firstRouterParam);
                }
            }
        }

        $uuid = SubjectUtils::getUUID();
        if ( ! $uuid) {
            throw new PreconditionRequiredHttpException(trans("errors.precondition_request"));
        }

        $user = null;
        if (config('auth.guards.api.provider')) {
            $user = Auth::guard('api')->user();
        }

//        try {
//            if ($user) {
//                // 使用 try 包裹，以捕捉 token 过期所抛出的 TokenExpiredException  异常
//                // 检测用户的登录状态，如果正常则通过
//                $token = $user->token();
//
//                if ($token && $token->expires_at && Carbon::now()->greaterThan($token->expires_at)) {
//                    $token->delete();
//                    //throw new AuthenticationException('token失效');
//                    throw new HttpException(401, 'token失效');
//                }
//            }
//        } catch (HttpException $httpException) {
//            throw $httpException;
//        } catch (\Exception $exception) {
//            \Log::error('token 过期校验 error');
//            \Log::warning($exception);
//        }

        //如果user存在,检查user和uuid是否一致
        if ($user && AppUtils::isProduction()) {
            $subject = $user->subject;
            if ( ! $subject->base) {
                $subject = $subject->baseSubject();
            }

            $adminUUID = SubjectUtils::getConfigByOwner(SubjectConfigConstants::OWNER_CONFIG_ADMIN_WECHAT_UUID);

            if ($subject->uuid != $uuid && $adminUUID != $uuid) {
                \Log::warning("当前请求用户不属于该uuid:" . $subject->uuid . "," . $uuid);
                throw new ResourceException("当前请求用户不属于该uuid");
            }
        }

        //$requestType = $request->header('REQUEST_TYPE', 'SERVER');

        //if ($requestType && ! in_array($requestType, $this->requestTypes)) {
        //    throw new PreconditionFailedHttpException(trans("errors.precondition_failed"));
        //}

        $request->headers->set("mode", "api");

        return $next($request);
    }

}
