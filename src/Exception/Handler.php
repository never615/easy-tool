<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Exception;

use Encore\Admin\Facades\Admin;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\Exceptions\MissingScopeException;
use RedisException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;
use Illuminate\Support\Facades\Log;

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
        QueryException::class,
        MaxAttemptsExceededException::class,
        RedisException::class,
        \Mallto\Tool\Exception\HttpException::class,
    ];


    /**
     * Report or log an exception.
     *
     * This is a great spot to send exceptions to Sentry, Bugsnag, etc.
     *
     * @param Throwable $exception
     *
     * @return void
     * @throws Throwable
     */
    public function report(Throwable $exception)
    {
        parent::report($exception);
    }


    /**
     * 是否可以接受json响应
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return bool
     */
    public function canAcceptJson(\Illuminate\Http\Request $request)
    {
        return ($request->ajax() && !$request->pjax() && $request->acceptsAnyContentType()) || $request->accepts('application/json');
    }


    /**
     * 是否可以接受html响应
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return bool
     */
    public function canAcceptHtml(\Illuminate\Http\Request $request)
    {
        return $request->accepts('text/html');
    }


    /**
     * Render an exception into an HTTP response.
     *
     * @param \Illuminate\Http\Request $request
     * @param Throwable $exception
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\Response|\Symfony\Component\HttpFoundation\Response
     * @throws Throwable
     */
    public function render($request, Throwable $exception)
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($request->expectsJson()) {
            if (Admin::user()) {
                $response = $this->interJsonHandler($exception, $request);

                $content = json_decode($response->getContent(), true);

                return response()->json([
                    'status' => false,
                    'message' => $content['error'] ?? $exception->getMessage(),
                    'error' => $content['error'] ?? $exception->getMessage(),
                ]);
            } else {
                return $this->interJsonHandler($exception, $request);
            }
        } else {
            //走到这大概率是管理端请求
            if ($exception instanceof TokenMismatchException) {
                return redirect()->guest(config('app.url') . config("admin.admin_login"));
            }

            //如果是管理端请求
            if (Admin::user() && $request->ajax() && !$request->pjax()) {
                $response = $this->interJsonHandler($exception, $request);

                $content = json_decode($response->getContent(), true);

                $error = new MessageBag([
                    'title' => $content['error'] ?? $exception->getMessage(),
                ]);

                return back()->with(compact('error'))->withInput();
            } else {

                if ($this->canAcceptHtml($request)) {
                    //没有请求json响应
                    $response = $this->interJsonHandler($exception, $request);
                    $content = json_decode($response->getContent(), true);

                    $newException = new \Mallto\Tool\Exception\HttpException($response->getStatusCode(),
                        $content['error'] ?? $exception->getMessage(), JSON_UNESCAPED_UNICODE);

                    return parent::render($request, $newException);
                } else {
                    if ($this->canAcceptJson($request)) {
                        return $this->interJsonHandler($exception, $request);
                    } else {
                        //如果是来自 api 的请求则响应 json
                        if (str_starts_with($request->path(), 'api') || str_starts_with($request->path(),
                                'admin/api')) {
                            return $this->interJsonHandler($exception, $request);
                        } else {
                            //没有请求json响应
                            $response = $this->interJsonHandler($exception, $request);
                            $content = json_decode($response->getContent(), true);

                            $newException = new \Mallto\Tool\Exception\HttpException($response->getStatusCode(),
                                $content['error'] ?? $exception->getMessage(), JSON_UNESCAPED_UNICODE);

                            return parent::render($request, $newException);
                        }
                    }
                }
            }
        }
    }


    /**
     * 返回的是json的错误响应
     *
     * @param      $exception
     * @param      $request
     * @param bool $isAdmin
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\Response
     */
    protected function interJsonHandler(Throwable $exception, $request, $isAdmin = false)
    {
//        if ($exception instanceof InternalHttpException) {
//            Log::error("系统内部异常");
//            Log::warning($exception);
//        }
        if ($exception instanceof HttpException) {
            if ($exception instanceof ServiceUnavailableHttpException) {
                return response()->json($this->responseData([
                    "error" => "系统维护中",
                ], $exception), $exception->getStatusCode(), [], JSON_UNESCAPED_UNICODE);
            }

            if ($exception instanceof \Mallto\Tool\Exception\HttpException) {
                return response()->json($exception->getResponseContent(), $exception->getStatusCode(), [],
                    JSON_UNESCAPED_UNICODE);
            } else {
                //其他系统定义的异常
                $data = [
                    "error" => $exception->getMessage(),
//                    "code"  => $exception->getCode() ?? 0,
                ];
                if ($code = $exception->getCode()) {
                    $data["code"] = $code;
                }

                return response()->json($this->responseData($data, $exception->getMessage()),
                    $exception->getStatusCode(), [], JSON_UNESCAPED_UNICODE);
            }
        } else {
            if ($exception instanceof ModelNotFoundException) {
//                $arr = explode('\\', $exception->getModel());
                Log::warning('ModelNotFoundException', $request->all() ?? []);
//                Log::warning($exception);

                return response()->json($this->responseData([
                    "error" => trans("errors.not_found"),
                ], $exception), '404', [], JSON_UNESCAPED_UNICODE);

            } elseif ($exception instanceof OAuthServerException) {
                throw new HttpException($exception->getHttpStatusCode(), $exception->getMessage());
            } elseif ($exception instanceof ClientException) {
                return response()->json($this->responseData([
                    "error" => $exception->getMessage(),
                ], $exception), $exception->getCode(), [], JSON_UNESCAPED_UNICODE);
            } elseif ($exception instanceof ServerException) {
                return response()->json($this->responseData([
                    "error" => $exception->getMessage(),
                ], $exception), $exception->getCode(), [], JSON_UNESCAPED_UNICODE);
            } elseif ($exception instanceof AuthenticationException) {
                return $this->unauthenticated($request, $exception);
            } elseif ($exception instanceof ValidationException) {
                return $this->invalidJson($request, $exception);
            } elseif ($exception instanceof DecryptException) {
                //解密失败
                throw new ValidationHttpException("解密失败");
            } elseif ($exception instanceof MissingAbilityException) {
//                Log::info($exception);
//                Log::info($exception->scopes());
                return response()->json($this->responseData([
                    'error' => $exception->getMessage(),
                ], $exception), 401, [], JSON_UNESCAPED_UNICODE);
            } elseif ($exception instanceof TokenMismatchException) {
                return response()->json($this->responseData([
                    'error' => '登录失效请重新登录,' . $exception->getMessage(),
                ], $exception), 401, [], JSON_UNESCAPED_UNICODE);
            } elseif ($exception instanceof QueryException) {
                if (str_contains($exception->getMessage(), "Invalid text representation:")) {
                    $requestId = $exception->getBindings()[0] ?? "";
                    Log::warning(Str::replaceArray('ERROR', ['xxx'], $exception->getMessage())); //不替换error,会自动打一条日志
                    Log::warning($exception->getTraceAsString());
                    throw new ResourceException("查询参数错误,无效的id:" . $requestId);
                }

                if ($exception->getCode() == '23505') {
                    Log::warning("QueryException:23505");
                    //Log::warning($exception);
                    Log::warning(Str::replaceArray('ERROR', ['xxx'], $exception->getMessage())); //不替换error,会自动打一条日志
                    Log::warning($exception->getTraceAsString());
                    throw new ResourceException('数据已提交或创建成功,请刷新查看');
                }

                Log::warning("QueryException");
                Log::warning($exception);
                throw new ResourceException("无效的查询");
            } elseif ($exception instanceof \PDOException) {
                $msg = preg_replace('/(.*)\(.*\)/', "$1", $exception->getMessage());
                Log::error("PDOException");
                Log::warning($exception);
                throw new ResourceException($msg);
            } elseif ($exception instanceof RequestException) {
                return response()->json(["error" => "网络繁忙,请重试:" . $exception->getMessage()], 422, [],
                    JSON_UNESCAPED_UNICODE);
            } else {
                //Log::error('服务器繁忙');
                Log::warning($exception);
                throw new InternalHttpException(trans("errors.internal_error"));
            }
        }
    }


    /**
     * Convert an authentication exception into an unauthenticated response.
     *
     * @param \Illuminate\Http\Request $request
     * @param AuthenticationException $exception
     *
     * @return \Illuminate\Http\Response
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->expectsJson()) {
            return response()->json($this->responseData([
//                        'error' => trans("errors.unauthenticated").','.$exception->getMessage(),
                'error' => "登录失效,请重新登录或刷新:" . $exception->getMessage(),
            ], $exception), 401, [], JSON_UNESCAPED_UNICODE);
        }

        if (Admin::user()) {
            return redirect()->guest(config('app.url') . config("admin.admin_login"));
        } else {
            $e = new \Mallto\Tool\Exception\AuthorizeFailedException();

            return $this->toIlluminateResponse($this->renderHttpException($e), $e);
        }
    }


    /**
     * Convert a validation exception into a JSON response.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Illuminate\Validation\ValidationException $exception
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function invalidJson($request, ValidationException $exception)
    {
        $protocolVersion = $request->header("protocol_version", 1);
        if ($protocolVersion == 2) {
            return response()->json([
                'errors' => $exception->errors(),
                "message" => $exception->getMessage(),
            ], $exception->status, [], JSON_UNESCAPED_UNICODE);
        } else {
//            return response()->json($exception->errors(), $exception->status, [], JSON_UNESCAPED_UNICODE);
            return response()->json($this->responseData([
                "error" => array_first($exception->errors())[0] ?? $exception->getMessage(),
            ], $exception), $exception->status, [], JSON_UNESCAPED_UNICODE);

        }
    }


    protected function responseData($data, $exception)
    {
        return array_merge($data, [
            "message" => $data["error"] ?? $exception->getMessage(),
        ]);
    }


    /**
     * Get the view used to render HTTP exceptions.
     *
     * @param \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e
     *
     * @return string
     */
    protected function getHttpExceptionView(HttpExceptionInterface $e)
    {
        return "tooL_errors::{$e->getStatusCode()}";
    }

}
