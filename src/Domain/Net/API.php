<?php

namespace Mallto\Tool\Domain\Net;

use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Promise;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Jobs\LogJob;
use Mallto\Tool\Utils\AppUtils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class API
{

    /**
     * Http instance.
     *
     * @var
     */
    protected $http;

    /**
     * Optional slug for logging tag.
     *
     * @var string|null
     */
    protected $slug;

    const GET = 'get';
    const POST = 'post';
    const JSON = 'json';


    /**
     * 最大重试次数。
     *
     * LaravelS 常驻 Worker 中，每次外部 HTTP 调用都占用一个 Worker 进程，
     * 重试次数过多会导致所有 Worker 同时阻塞，accept queue 满溢，健康探针 timeout。
     *
     * 每次重试有 Guzzle exponentialDelay：retry1=1s, retry2=2s...
     * 加上 timeout=8s，最坏耗时 = (8+delay) * (MAX_RETRIES+1)
     * MAX_RETRIES=1 → 最坏 8+1+8 = 17s，可接受。
     *
     * 注意：原来 MAX_RETRIES=2 但判断是 retries > MAX_RETRIES，
     * 实际允许 3 次 retry（4 次总请求），最坏 47s，故障根因。
     */
    const MAX_RETRIES = 1;


    /**
     * Return the http instance.
     *
     * @return
     */
    public function getHttp()
    {
        if ($this->http === null) {
            $this->http = new Http();
        }

        if (count($this->http->getMiddlewares()) === 0) {
            $this->registerHttpMiddlewares();
        }

        return $this->http;
    }


    /**
     * Set the http instance.
     *
     * @param Http $http
     *
     * @return $this
     */
    protected function setHttp(Http $http)
    {
        $this->http = $http;

        return $this;
    }


    /**
     * Register Guzzle middlewares.
     */
    protected function registerHttpMiddlewares()
    {
        // log
        if (config('app.log.third_api')) {
            $this->http->addMiddleware($this->logMiddleware());
        }
        // retry
        $this->http->addMiddleware($this->retryMiddleware());
    }


    /**
     * Log the request.
     *
     * @return \Closure
     */
    protected function logMiddleware()
    {

        $requestId = AppUtils::create_uuid();

        $startTime = 0;
        $endTime = 0;

        $uuid = SubjectUtils::getUUIDNoException() ?: 0;

        return Middleware::tap(function (RequestInterface $request, $options) use (
            $requestId,
            &$startTime,
            $uuid
        ) {

            if (!$this->shouldLogOperation($request)) {
                return;
            }
            if (!AppUtils::isProduction()) {
                $startTime = microtime(true);
            }

            try {
                $logJob = new LogJob('logThirdPart', [
                    'uuid' => $uuid,
                    'request_id' => $requestId,
                    'tag' => $this->getSlug(),
                    'action' => '请求',
                    'method' => $request->getMethod(),
                    'url' => $request->getUri(),
                    'headers' => json_encode($request->getHeaders(), JSON_UNESCAPED_UNICODE),
                    'body' => is_null(json_decode($request->getBody())) ? json_encode(AppUtils::httpQueryBuildReverse($request->getBody()),
                        JSON_UNESCAPED_UNICODE) : $request->getBody() . "",
                    'subject_id' => $uuid ? SubjectUtils::getSubjectId() : 1,
                ]);

                if (config('app.log.dispatch_now')) {
                    dispatch_now($logJob);
                } else {
                    dispatch($logJob);
                }

            } catch (\Exception $exception) {
                Log::error("记录第三方方请求日志错误");
                Log::warning($exception);
            }


        }, function (RequestInterface $request, $options, Promise $response) use (
            $requestId,
            &$startTime,
            $endTime,
            $uuid
        ) {
            if (!$this->shouldLogOperation($request)) {
                return;
            }

            $response->then(function (ResponseInterface $response) use (
                $request,
                $requestId,
                &$startTime,
                $uuid
            ) {
                $requestTime = 0;
                if (!AppUtils::isProduction()) {
                    $endTime = microtime(true);
                    $requestTime = round($endTime - $startTime, 3);
                }

                $logJob = new LogJob('logThirdPart', [
                    'uuid' => $uuid,
                    'request_id' => $requestId,
                    'tag' => $this->getSlug(),
                    'action' => '响应',
                    'method' => $request->getMethod(),
                    'url' => $request->getUri(),
                    'headers' => json_encode($request->getHeaders(), JSON_UNESCAPED_UNICODE),
                    'body' => $response->getBody()->getContents(),
                    'status' => $response->getStatusCode(),
                    'request_time' => $requestTime,
                    'subject_id' => $uuid ? SubjectUtils::getSubjectId() : 1,
                ]);

                if (config('app.log.dispatch_now')) {
                    dispatch_now($logJob);
                } else {
                    dispatch($logJob);
                }
            });
        });
    }


    /**
     * Return retry middleware.
     *
     * @return \Closure
     */
    protected function retryMiddleware()
    {
        return Middleware::retry(function (
            $retries,
            RequestInterface $request,
            ResponseInterface $response = null,
            $exception = null
        ) {
            // retries 是已重试次数；>= MAX_RETRIES 时停止（原来是 > MAX_RETRIES，实际多允许一次）
            if ($retries >= self::MAX_RETRIES) {
                return false;
            }

            $uuid = SubjectUtils::getUUIDNoException() ?: 0;

            if ($this->isServerError($response)
                || $this->isConnectError($exception)
                || $this->isInvalidResponse($response)
            ) {
                if (config('app.log.third_api')) {
                    $logJob = new LogJob("logThirdPart", [
                        'uuid' => $uuid,
                        "tag" => $this->getSlug(),
                        "action" => 'Retry请求',
                        "method" => $request->getMethod(),
                        "url" => $request->getUri(),
                        "headers" => json_encode($request->getHeaders(), JSON_UNESCAPED_UNICODE),
                        "body" => \GuzzleHttp\json_encode([
                            "request" => $request->getBody()->getContents(),
                            "response" => $response ? 'status code: ' . $response->getStatusCode() : ($exception ? $exception->getMessage() : ""),
                        ], JSON_UNESCAPED_UNICODE),
                        'subject_id' => $uuid ? SubjectUtils::getSubjectId() : 1,
                    ]);

                    if (config('app.log.dispatch_now')) {
                        dispatch_now($logJob);
                    } else {
                        dispatch($logJob);
                    }
                }

                return true;
            } else {
                return false;
            }
        }, function (int $retries): int {
            // 限制 retry delay 上限为 500ms（Guzzle 默认 exponentialDelay 最坏 2s+）
            // 在 LaravelS 常驻 Worker 中，delay 直接占用 Worker 进程，必须控制上限
            return min(500, (int)(1000 * pow(2, $retries - 1)));
        });
    }


    /**
     * @param ResponseInterface $response
     *
     * @return bool
     */
    protected function isServerError(ResponseInterface $response = null)
    {
        return $response && $response->getStatusCode() >= 500;
    }


    /**
     * @param TransferException $exception
     *
     * @return bool
     */
    protected function isConnectError(TransferException $exception = null)
    {
        if ($exception && (strpos($exception->getMessage(), ' Connection reset by peer'))) {
            return true;
        }

        if ($exception instanceof ConnectException) {
            return true;
        }

        if ($exception) {
            Log::error('基础网络库:其他异常');
            Log::warning($exception);
            Log::warning($exception->getRequest()->getUri()->getHost());
            Log::warning($exception->getRequest()->getUri()->getPath());
        }

        return false;
    }


    /**
     * @param ResponseInterface $response
     *
     * @return bool
     */
    protected function isInvalidResponse(ResponseInterface $response = null)
    {
        return $response === false;
    }


    /**
     * 客户端异常日志
     *
     * @param ClientException $clientException
     */
    protected function clientExceptionLog(ClientException $clientException)
    {
        try {
            Log::error('clientException:' . static::class);
            Log::warning(new Exception());
            Log::warning($clientException);
            Log::warning($clientException->getResponse()->getBody()->getContents());
        } catch (\Exception $exception) {
            Log::error('clientExceptionLog');
            Log::warning($exception);
        }
    }


    /**
     * @param  $request
     *
     * @return bool
     */
    protected function shouldLogOperation($request)
    {
        return config('app.log.third_api')
            && !$this->inExceptArray($request);
    }


    /**
     * Determine if the request has a URI that should pass through CSRF verification.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return bool
     */
    protected function inExceptArray($request)
    {
        $excepts = config('app.third_api_except.except') ?? [];
        foreach ($excepts as $except) {
            if ($except !== '/') {
                $except = trim($except, '/');
            }

            $methods = [];

            if (Str::contains($except, ':')) {
                [$methods, $except] = explode(':', $except);
                $methods = explode(',', $methods);
            }

            $methods = array_map('strtoupper', $methods);

            if ($request->is($except) &&
                (empty($methods) || in_array($request->method(), $methods))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Set logging slug.
     */
    public function setSlug(string $slug)
    {
        $this->slug = $slug;
        return $this;
    }

    /**
     * Get logging slug, defaulting to class basename if not set.
     */
    public function getSlug(): string
    {
        return $this->slug ?: class_basename(static::class);
    }

}
