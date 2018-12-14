<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Exception;


use Encore\Admin\Facades\Admin;
use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Exceptions\MissingScopeException;
use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
     * @throws Exception
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
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\Response|\Symfony\Component\HttpFoundation\Response
     */
    public function render($request, Exception $exception)
    {
        DB::rollBack();
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

                return back()->with(compact('error'))->withInput();
            } else {
                return parent::render($request, $exception);
            }
        }
    }


    /**
     * Convert an authentication exception into an unauthenticated response.
     *
     * @param  \Illuminate\Http\Request $request
     * @param AuthenticationException   $exception
     * @return \Illuminate\Http\Response
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->expectsJson()) {
            return response()
                ->json(['error' => trans("errors.unauthenticated").','.$exception->getMessage()],
                    401, [], JSON_UNESCAPED_UNICODE);
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
    protected function interJsonHandler(Exception $exception, $request, $isAdmin = false)
    {
//        if ($exception instanceof InternalHttpException) {
//            \Log::error("系统内部异常");
//            \Log::warning($exception);
//        }

        if ($exception instanceof HttpException) {
            if ($exception instanceof ServiceUnavailableHttpException) {
                return response()->json(["error" => "系统维护中"], $exception->getStatusCode(), [],
                    JSON_UNESCAPED_UNICODE);
            }

            if ($exception instanceof \Mallto\Tool\Exception\HttpException) {
                return response()->json($exception->getResponseContent(), $exception->getStatusCode(), [],
                    JSON_UNESCAPED_UNICODE);
            } else {
                $data = [
                    "error" => $exception->getMessage(),
                ];

                if ($exception->getCode()) {
                    $data["code"] = $exception->getCode();
                }

                return response()
                    ->json($data, $exception->getStatusCode(), [],
                        JSON_UNESCAPED_UNICODE);
            }
        } else {
            if ($exception instanceof ModelNotFoundException) {
//                $arr = explode('\\', $exception->getModel());

                return response()->json(["error" => trans("errors.not_found")], '404', [],
                    JSON_UNESCAPED_UNICODE);
//                return response()->json(["error" => trans("errors.not_found").",".array_last($arr)], '404', [],
//                    JSON_UNESCAPED_UNICODE);
            } elseif ($exception instanceof OAuthServerException) {
                throw new HttpException($exception->getCode(), $exception->getMessage());
            } elseif ($exception instanceof ClientException) {
                return response()->json(["error" => $exception->getMessage()], $exception->getCode(), [],
                    JSON_UNESCAPED_UNICODE);
            } elseif ($exception instanceof ServerException) {
                return response()->json(["error" => $exception->getMessage()], $exception->getCode(), [],
                    JSON_UNESCAPED_UNICODE);
            } elseif ($exception instanceof AuthenticationException) {
                return $this->unauthenticated($request, $exception);
            } elseif ($exception instanceof ValidationException) {
                return $this->invalidJson($request, $exception);
            } elseif ($exception instanceof DecryptException) {
                //解密失败
                throw new ValidationHttpException("解密失败");
            } elseif ($exception instanceof MissingScopeException) {
                //没有对应作用域的授权
                throw new PermissionDeniedException("没有权限访问该的接口");
            } elseif ($exception instanceof TokenMismatchException) {
                return $this->unauthenticated($request, new AuthenticationException($exception->getMessage()));
            } elseif ($exception instanceof QueryException) {
                \Log::error("QueryException");
                \Log::warning($exception);
                throw new InternalHttpException("无效的搜索(SQL错误)");
            } elseif ($exception instanceof \PDOException) {
                $msg = preg_replace('/(.*)\(.*\)/', "$1", $exception->getMessage());
                \Log::error("PDOException");
                \Log::warning($exception);
                throw new ResourceException($msg);
            } elseif ($exception instanceof RequestException) {
                return response()->json(["error" => "网络繁忙,请重试:".$exception->getMessage()], 422, [],
                    JSON_UNESCAPED_UNICODE);
            } else {
                throw new InternalHttpException(trans("errors.internal_error"));
            }
        }
    }


    /**
     * Convert a validation exception into a JSON response.
     *
     * @param  \Illuminate\Http\Request                   $request
     * @param  \Illuminate\Validation\ValidationException $exception
     * @return \Illuminate\Http\JsonResponse
     */
    protected function invalidJson($request, ValidationException $exception)
    {

        $protocolVersion = $request->header("protocol_version", 1);
        if ($protocolVersion == 2) {
            return response()->json(
                [
                    'errors'  => $exception->errors(),
                    "message" => $exception->getMessage(),
                ], $exception->status, [], JSON_UNESCAPED_UNICODE);
        } else {
//            return response()->json($exception->errors(), $exception->status, [], JSON_UNESCAPED_UNICODE);
            return response()->json([
                "error" => array_first($exception->errors())[0] ?? $exception->getMessage(),
            ], $exception->status, [], JSON_UNESCAPED_UNICODE);

        }
    }


}
