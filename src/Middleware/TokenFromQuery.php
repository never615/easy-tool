<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * 从 URL 查询参数中读取 token 并注入到 Authorization 头。
 *
 * 用于文件下载等场景：浏览器直接打开链接（window.open / location.href）
 * 无法携带自定义 header，前端可将 token 放在 URL 参数中，例如：
 *   /admin/api/area_export?token=xxxxxx
 *
 * 本中间件会在 Sanctum 等 auth 中间件执行前，将 query 中的 token
 * 填充到 Authorization: Bearer {token} 头，使后续鉴权流程正常工作。
 *
 * 如果 Authorization 头已存在，则不做任何覆盖，保持原有行为。
 */
class TokenFromQuery
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // 仅在 Authorization 头不存在时，才从 query 参数 token 中读取
        if (!$request->headers->has('Authorization')) {
            $token = $request->query('token');
            if ($token) {
                $request->headers->set('Authorization', 'Bearer ' . $token);
            }
        }

        return $next($request);
    }
}

