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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

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
        // Sanctum 通过 tokenable_type 解析已不存在的模型类时（如旧 token 中记录的类已被移除），
        // 会抛出 PHP \Error（Class not found）。
        // render() 中已将其转为 AuthenticationException（401），此处跳过 report，
        // 避免产生误导性的错误日志。
        if ($exception instanceof \Error &&
            preg_match('/Class .* not found/', $exception->getMessage())) {
            $trace = $exception->getTraceAsString();
            if (str_contains($trace, 'Guard.php') ||
                str_contains($trace, 'sanctum') ||
                str_contains($trace, 'HasRelationships')) {
                return;
            }
        }

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
                $message = $this->responseContentMessage((array)$content, $exception);

                return response()->json([
                    'status' => false,
                    'message' => $message,
                    'error' => $message,
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
                    'title' => $this->responseContentMessage((array)$content, $exception),
                ]);

                return back()->with(compact('error'))->withInput();
            } else {

                if ($this->canAcceptHtml($request)) {
                    //没有请求json响应
                    $response = $this->interJsonHandler($exception, $request);
                    $content = json_decode($response->getContent(), true);

                    $newException = new \Mallto\Tool\Exception\HttpException($response->getStatusCode(),
                        $this->responseContentMessage((array)$content, $exception), JSON_UNESCAPED_UNICODE);

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
                                $this->responseContentMessage((array)$content, $exception), JSON_UNESCAPED_UNICODE);

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
                return response()->json($this->responseData($exception->getResponseContent(), $exception), $exception->getStatusCode(), [],
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

                return response()->json($this->responseData($data, $exception),
                    $exception->getStatusCode(), [], JSON_UNESCAPED_UNICODE);
            }
        } else {
            if ($exception instanceof ModelNotFoundException) {
//                $arr = explode('\\', $exception->getModel());
//                Log::warning('ModelNotFoundException', $request->all() ?? []);
//                Log::warning($exception);

                return response()->json($this->responseData([
                    "error" => trans("errors.not_found")??$exception->getMessage(),
                ], $exception), '404', [], JSON_UNESCAPED_UNICODE);

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
            } elseif ($exception instanceof \RedisException) {
                // Redis 重启加载快照期间，所有命令均返回
                // "LOADING Redis is loading the dataset into memory"，
                // 此时服务暂时不可用，应返回 503 而非 500，且不必记录为 error 级别。
                if (str_contains($exception->getMessage(), 'LOADING')) {
                    Log::warning('RedisException: Redis is loading the dataset, returning 503', [
                        'message' => $exception->getMessage(),
                    ]);
                    return response()->json($this->responseData([
                        'error' => '系统缓存服务初始化中，请稍后重试',
                    ], $exception), 503, [], JSON_UNESCAPED_UNICODE);
                }
                Log::error('RedisException');
                Log::warning($exception);
                throw new InternalHttpException('缓存服务异常，请稍后重试');
            } elseif ($exception instanceof RequestException) {
                return response()->json(["error" => "网络繋忙,请重试:" . $exception->getMessage()], 422, [],
                    JSON_UNESCAPED_UNICODE);
            } elseif ($exception instanceof \Error) {
                // 当 Sanctum 尝试通过 tokenable_type 解析已不存在的模型类时（如旧 token 中记录的类已被移除），
                // 会抛出 PHP \Error（Class not found）。此时应视为认证失效，抛出 AuthenticationException，
                // 让前端自动处理登出，而不是返回 500 错误。
                $trace = $exception->getTraceAsString();
                if (preg_match('/Class .* not found/', $exception->getMessage()) &&
                    (str_contains($trace, 'Guard.php') || str_contains($trace, 'sanctum') || str_contains($trace, 'HasRelationships'))) {
                    Log::warning('Sanctum tokenable class not found, treating as AuthenticationException: ' . $exception->getMessage());
                    throw new AuthenticationException('登录失效,请重新登录');
                }
                //Log::error('服务器繁忙');
                Log::warning($exception);
                throw new InternalHttpException(trans("errors.internal_error"));
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
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse|\Illuminate\Http\Response
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->expectsJson()) {
//            Log::debug($exception);
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
            $message = $this->nonEmptyMessage($exception->getMessage(), array_first($exception->errors())[0] ?? null);

            return response()->json([
                'errors' => $exception->errors(),
                "message" => $message,
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
        if (array_key_exists('error', $data)) {
            $data['error'] = $this->nonEmptyMessage($data['error'], $data['message'] ?? $exception);
        }

        $data['message'] = $this->nonEmptyMessage($data['message'] ?? null, $data['error'] ?? $exception);
        $this->logWarningStackWhenUsingGenericMessage($data['message'], $exception);

        return $data;
    }


    protected function responseContentMessage(array $data, Throwable $exception): string
    {
        return $this->nonEmptyMessage($data['error'] ?? null, $data['message'] ?? $exception);
    }


    protected function nonEmptyMessage($message, $fallback = null): string
    {
        foreach ([$message, $fallback] as $candidate) {
            if ($candidate instanceof Throwable) {
                $candidate = $candidate->getMessage();
            }

            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        $message = $this->translatedInternalErrorMessage();
        if ($message !== null) {
            return $message;
        }

        return $this->fallbackInternalErrorMessage();
    }


    protected function logWarningStackWhenUsingGenericMessage(string $message, Throwable $exception): void
    {
        if (!$this->shouldLogWarningStackForGenericMessage($message)) {
            return;
        }

        Log::warning('Handler responded with generic internal error message', [
            'resolved_message' => $message,
            'exception_class' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }


    protected function shouldLogWarningStackForGenericMessage(string $message): bool
    {
        $genericMessages = [$this->fallbackInternalErrorMessage()];
        $translatedInternalErrorMessage = $this->translatedInternalErrorMessage();
        if ($translatedInternalErrorMessage !== null) {
            $genericMessages[] = $translatedInternalErrorMessage;
        }

        return in_array($message, $genericMessages, true);
    }


    protected function translatedInternalErrorMessage(): ?string
    {
        $message = trans('errors.internal_error');
        if (is_string($message) && trim($message) !== '' && $message !== 'errors.internal_error') {
            return $message;
        }

        return null;
    }


    protected function fallbackInternalErrorMessage(): string
    {
        return '系统繁忙,请稍后重试...';
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
//        return "errors.{$e->getStatusCode()}";
    }

}
