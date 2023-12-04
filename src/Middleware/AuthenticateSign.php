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
use Mallto\Tool\Exception\SignException;
use Mallto\Tool\Utils\SignUtils;

/**
 * 管理端权限过滤
 *
 * @deprecated
 *
 *
 *
 * Class AuthenticateAdmin
 *
 * @package App\Http\Middleware
 */
class AuthenticateSign
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
    public function handle($request, Closure $next)
    {

        $inputs = $request->all();
        $key = null;
        if (SignUtils::verifySign($inputs, $key)) {
            //pass
            return $next($request);
        } else {
            throw new SignException(trans("errors.sign_error"));
        }


    }
}
