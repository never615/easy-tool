<?php

namespace Mallto\Tool\Exception;


use Encore\Admin\Facades\Admin;
use Exception;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Exceptions\MissingScopeException;
use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 *
 * 异常处理:
 * 1. 检查转化所有到来的异常
 * 2. 检查请求的方式,是期望json响应还是视图响应,然后针对异常做不同的处理
 *
 * Class Handler
 *
 * @package Mallto\Tool\Exception
 */
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


        if ($exception instanceof NotFoundHttpException) {
            \Log::info("not found http");
            \Log::info($request->url());
        }


        if ($request->expectsJson()) {
            if (Admin::user()) {
                return $this->interJsonHandler($exception, $request, true);
            } else {
                return $this->interJsonHandler($exception, $request);
            }
        } else {
            if ($exception instanceof TokenMismatchException) {
                return redirect()->guest(config('app.url').config("admin.admin_login"));
            }

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

        if (Admin::user()) {
            return redirect()->guest(config('app.url').config("admin.admin_login"));
        } else {
            $e = new \Mallto\Tool\Exception\AuthorizeFailedException();

            return $this->toIlluminateResponse($this->renderHttpException($e), $e);
        }
    }


    /**
     * 返回的是json的错误响应
     *
     * @param      $exception
     * @param      $request
     * @param bool $isAdmin
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\Response
     */
    protected function interJsonHandler($exception, $request, $isAdmin = false)
    {
        if ($exception instanceof HttpException) {
            if ($exception instanceof ServiceUnavailableHttpException) {
                return response()->json(["error" => "系统维护中"], $exception->getStatusCode());
            }

            return response()->json(["error" => $exception->getMessage()], $exception->getStatusCode());

        } else {
            if ($exception instanceof ModelNotFoundException) {
                $arr = explode('\\', $exception->getModel());

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
            } elseif ($exception instanceof TokenMismatchException) {
                return $this->unauthenticated($request, $exception);
            } elseif ($exception instanceof \PDOException) {
                $msg = preg_replace('/(.*)\(.*\)/', "$1", $exception->getMessage());
                throw new ResourceException($msg);
            } elseif ($exception instanceof \Overtrue\Socialite\AuthorizeFailedException) {
                return $this->unauthenticated($request, $exception);
            } else {
                //todo 记录 通知
                throw new InternalHttpException(trans("errors.internal_error"));
            }
        }
    }
}
