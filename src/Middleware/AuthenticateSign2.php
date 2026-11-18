<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

/**
 * 签名校验
 *
 * 由 PhpStorm 创建。
 * 作者： never615
 * 日期： 11/11/2016
 * 时间： 8:16 下午
 */

namespace Mallto\Tool\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * 管理端权限过滤
 *
 * 使用appid和secret
 *
 * @deprecated
 *
 *
 * AuthenticateAdmin 类
 *
 * @package App\Http\Middleware
 */
class AuthenticateSign2
{

    use SignCheckTrait;

    protected $except = [
    ];


    /**
     * 处理传入请求。
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
