<?php

namespace Mallto\Tool\Exception;


use Encore\Admin\Facades\Admin;
use Exception;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Exceptions\MissingScopeException;
use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Component\Debug\Exception\FatalErrorException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that should not be reported.
     *
     * @var array
     */
    protected $dontReport = [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Symfony\Component\HttpKernel\Exception\HttpException::class,
        \Illuminate\Database\Eloquent\ModelNotFoundException::class,
        \Illuminate\Session\TokenMismatchException::class,
        \Illuminate\Validation\ValidationException::class,
        \League\OAuth2\Server\Exception\OAuthServerException::class,
    ];

    /**
     * Report or log an exception.
     *
     * This is a great spot to send exceptions to Sentry, Bugsnag, etc.
     *
     * @param  \Exception $exception
     * @return void
     */
    public function report(Exception $exception)
    {
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Exception               $exception
     * @return \Illuminate\Http\Response
     */
    public function render($request, Exception $exception)
    {
        DB::rollBack();

        if ($request->expectsJson()) {
            if (Admin::user()) {
                return $this->interHandler($exception, $request, true);
            } else {
                return $this->interHandler($exception, $request);
            }
        } else {
            //如果是管理端请求
            if (Admin::user()) {
                $error = new MessageBag([
                    'title' => $exception->getMessage(),
                ]);

                return back()->with(compact('error'));
            } else {
                return parent::render($request, $exception);
            }
        }
    }


    /**
     * Convert an authentication exception into an unauthenticated response.
     *
     * @param  \Illuminate\Http\Request $request
     * @param                           $exception
     * @return \Illuminate\Http\Response
     */
    protected function unauthenticated($request, $exception)
    {
        if ($request->expectsJson()) {
            return response()
                ->json(['error' => trans("errors.unauthenticated").','.$exception->getMessage()], 401);
        }

        return redirect()->guest(config("common.admin_login"));
    }


    protected function interHandler($exception, $request, $isAdmin = false)
    {
        if ($exception instanceof HttpException) {
            if ($exception instanceof ServiceUnavailableHttpException) {
                return response()->json(["error" => "系统维护中"], $exception->getStatusCode());
            }

            return response()->json(["error" => $exception->getMessage()], $exception->getStatusCode());

        } else {
            if ($exception instanceof ModelNotFoundException) {
                $arr=explode('\\',$exception->getModel());
                return response()->json(["error" => trans("errors.not_found").",".array_last($arr)], '404');
            } elseif ($exception instanceof OAuthServerException) {
                throw new HttpException($exception->getCode(), $exception->getMessage());
            } elseif ($exception instanceof AuthenticationException) {
                return $this->unauthenticated($request, $exception);
            } elseif ($exception instanceof ValidationException) {
                return $this->convertValidationExceptionToResponse($exception, $request);
            } elseif ($exception instanceof DecryptException) {
                //解密失败
                throw new ValidationHttpException(trans("errors.validation_error"));
            } elseif ($exception instanceof MissingScopeException) {
                //没有对应作用域的授权
                throw new PermissionDeniedException("没有权限访问该的接口");
            } elseif($exception instanceof FatalErrorException) {
                throw new InternalHttpException($exception->getMessage());
            }else {
                //todo 记录 通知
                throw new InternalHttpException(trans("errors.internal_error"));
            }
        }
    }
}
