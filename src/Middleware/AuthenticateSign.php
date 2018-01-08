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


use Mallto\Tool\Exception\SignException;
use Closure;
use Mallto\Tool\Utils\SignUtils;

/**
 * 管理端权限过滤
 *
 * Class AuthenticateAdmin
 * @package App\Http\Middleware
 */
class AuthenticateSign
{
    protected $except = [
    ];

    /**
     * Handle an incoming request.
     * @param $request
     * @param Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {

        $inputs = $request->all();
        if (SignUtils::verifySign($inputs)) {
            //pass
            return $next($request);
        } else {
            throw new SignException(trans("errors.sign_error"));
        }


    }
}