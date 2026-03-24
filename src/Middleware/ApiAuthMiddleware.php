<?php
/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

/**
 * User: never615 <never615.com>
 * Date: 2026/1/28
 * Time: 16:00
 */

namespace Mallto\Tool\Middleware;

use Closure;
use Illuminate\Http\Request;
use Str;

class ApiAuthMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $authHeader = $request->header('Authorization');

        // 情况1：存在 Authorization 头
        if ($authHeader) {
            if (Str::startsWith($authHeader, 'Bearer ')) {
                // Sanctum 认证（复用 Laravel Sanctum 逻辑）
                if (auth('sanctum')->check()) {
                    return $next($request); // 认证成功，放行
                }
                return response()->json(['message' => 'Invalid token'], 401); // Token 无效，拒绝
            }
            // 非 Bearer 头（如 Basic），明确拒绝
            return response()->json(['message' => 'Unsupported auth scheme'], 401);
        }

        // 情况2：无 Authorization 头 → 尝试签名校验
        $validator = app(SignatureValidator::class);
        if ($validator->validate($request)) {
        // 签名通过：绑定外部应用关联的用户（如 ExternalApp::find( $ appId)->user）
        $user = $validator->getAuthenticatedUser();
            auth()->setUser($user); // 统一认证上下文
             $request->attributes->set('is_external', true); // 可选：标记来源供业务逻辑使用
            return $next($request);
        }

        return response()->json(['message' => 'Unauthenticated'], 401);
    }
}