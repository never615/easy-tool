<?php

namespace Mallto\Tool\Middleware;


use Closure;
use Encore\Admin\AppUtils;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionRequiredHttpException;

class RequestCheck
{

    /**
     * @param         $request
     * @param Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {

        $uuid = AppUtils::getUUID();
        $requestType = $request->header('REQUEST_TYPE');
        if (!$requestType || !$uuid) {
            throw new PreconditionRequiredHttpException(trans("errors.precondition_request"));
        }


        if (!in_array($requestType, config('mall.request_type'))) {
            throw new PreconditionFailedHttpException(trans("errors.precondition_failed"));
        }

        AppUtils::getSubject();

        return $next($request);
    }


}
