<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Middleware;

use Closure;
use Illuminate\Http\Request;
use Mallto\Tool\Data\AppSecret;
use Mallto\Tool\Data\AppSecretsPermission;
use Mallto\Tool\Exception\PermissionDeniedException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Class AutoThirdPermissionMiddleware
 *
 * @package Mallto\Tool\Middleware
 */
class AutoThirdPermissionMiddleware
{

    protected $except = [
    ];


    /**
     * Handle an incoming request.
     *
     * @param         $request
     * @param Closure $next
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $appId = $request->header('app_id');

        $appSecretUser = AppSecret::query()
            ->where('app_id', $appId)
            ->first();

        if ( ! $appSecretUser) {
            throw new PermissionDeniedException('该appid未注册墨兔开放平台，请联系管理员');
        }

        $routeName = $request->route()->getName();
        $routenameArr = explode('.', $routeName);

        if (count($routenameArr) == 2) {
            $subRouteName0 = $routenameArr[0];

            if ( ! AppSecretsPermission::query()->where('slug', $routeName)->exists()) {
                $routeName = $subRouteName0 . '.*';
            }

        }

        //做一下兼容，没有做权限的默认放行
        if (is_null($routeName)) {
            return $next($request);
        }

        //权限管理有该权限,检查开发者是否有该权限
        if ($appSecretUser->check($routeName)) {
            return $next($request);
        }

        throw new AccessDeniedHttpException(trans("errors.permission_denied"));
    }
}
