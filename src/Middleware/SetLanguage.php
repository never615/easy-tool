<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * 多语言设置
 *
 * Class SetLanguage
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
