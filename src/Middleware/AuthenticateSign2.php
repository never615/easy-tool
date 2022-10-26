<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

/**
 * 签名校验
 *
 * Created by PhpStorm.
 * User: never615
 * Date: 11/11/2016
 * Time: 8:16 PM
 */

namespace Mallto\Tool\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * 管理端权限过滤
 *
 * 使用appid和secret
 *
 * Class AuthenticateAdmin
 *
 * @package App\Http\Middleware
 */
class AuthenticateSign2
{

    use SignCheckTrait;

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
        return $this->check($request, $next);
    }

}
