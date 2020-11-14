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

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Mallto\Tool\Data\AppSecret;
use Mallto\Tool\Exception\PermissionDeniedException;
use Mallto\Tool\Exception\ResourceException;
use Mallto\Tool\Exception\SignException;
use Mallto\Tool\Utils\AppUtils;
use Mallto\Tool\Utils\SignUtils;
use Symfony\Component\HttpKernel\Exception\PreconditionRequiredHttpException;

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
