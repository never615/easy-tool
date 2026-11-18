<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * 多语言设置
 *
 * SetLanguage 类
 *
 * @package Mallto\Tool\Middleware
 */
class SetLanguage
{
    /**
     * @param Request $request
     * @param Closure $next
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $language = $request->header('language', $request->get('language'));

        app()->setLocale($language ?? 'zh-CN');

        return $next($request);
    }
}
