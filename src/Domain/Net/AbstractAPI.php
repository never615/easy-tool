<?php

namespace Mallto\Tool\Domain\Net;

use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Promise;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Mallto\Admin\Exception\NotSettingByProjectOwnerException;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Exception\InternalHttpException;
use Mallto\Tool\Exception\ThirdPartException;
use Mallto\Tool\Jobs\LogJob;
use Mallto\Tool\Utils\AppUtils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Illuminate\Support\Facades\Log;

/**
 * Class AbstractAPI.
 */
abstract class AbstractAPI
{

    /**
     * Http instance.
     *
     * @var
     */
    protected $http;

    const GET = 'get';
    const POST = 'post';
    const JSON = 'json';

    /**
     * 主体动态配置中配置的项目的key  SubjectConfig
     *
     * @var string
     */
    protected $SETTING_KEY_BASE_URL;

    const MAX_RETRIES = 2;

    /**
     * 请求的日志标识
     *
     * @var
     */
    protected $slug;

    /**
     * 默认的请求基地址
     *
     * @var
     */
    protected $baseUrl;


    /**
     * AbstractAPI constructor.
     */
    public function __construct()
    {
        if ( ! $this->slug) {
            throw new InternalHttpException('继承自AbstractAPI的类 没有设置slug');
        }
    }


    /**
     * 获取请求的基础url,url后已经拼接好/
     *
     * @param int $subjectId
     *
     * @return mixed
     */
    protected function getBaseUrl(int $subjectId)
    {
        if ($this->baseUrl) {
            return $this->baseUrl;
        }

        if (empty($this->SETTING_KEY_BASE_URL)) {
            throw new NotSettingByProjectOwnerException('未设置SETTING_KEY_BASE_URL');
        }

        $baseUrl = SubjectUtils::getDynamicKeyConfigByOwner($this->SETTING_KEY_BASE_URL, $subjectId);

        return $this->baseUrl = rtrim($baseUrl, '/') . '/';
    }


    /**
     * @param mixed $baseUrl
     */
    protected function setBaseUrl($baseUrl)
    {
        $this->baseUrl = $baseUrl;
    }


    /**
     * Return the http instance.
     *
     * @return
     */
    protected function getHttp()
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
     * Parse JSON from response and check error.
     *
     * @param string $method
     * @param array  $args
     *
     * @return Collection
     */
    protected function parseJSON($method, array $args)
    {
        $http = $this->getHttp();

        $contents = $http->parseJSON(call_user_func_array([ $http, $method ], $args));

        if (is_array($contents)) {
            $this->checkAndThrow($contents);
        }

        return new Collection($contents);
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

            if ( ! $this->shouldLogOperation($request)) {
                return;
            }
            if ( ! AppUtils::isProduction()) {
                $startTime = microtime(true);
            }

            try {
                $logJob = new LogJob('logThirdPart', [
                    'uuid'       => $uuid,
                    'request_id' => $requestId,
                    'tag'        => $this->slug,
                    'action'     => '请求',
                    'method'     => $request->getMethod(),
                    'url'        => $request->getUri(),
                    'headers'    => json_encode($request->getHeaders(), JSON_UNESCAPED_UNICODE),
                    'body'       => is_null(json_decode($request->getBody())) ? json_encode(AppUtils::httpQueryBuildReverse($request->getBody()),
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
            if ( ! $this->shouldLogOperation($request)) {
                return;
            }

            $response->then(function (ResponseInterface $response) use (
                $request,
                $requestId,
                &$startTime,
                $uuid
            ) {
                $requestTime = 0;
                if ( ! AppUtils::isProduction()) {
                    $endTime = microtime(true);
                    $requestTime = round($endTime - $startTime, 3);
                }

                $logJob = new LogJob('logThirdPart', [
                    'uuid'         => $uuid,
                    'request_id'   => $requestId,
                    'tag'          => $this->slug,
                    'action'       => '响应',
                    'method'       => $request->getMethod(),
                    'url'          => $request->getUri(),
                    'headers'      => json_encode($request->getHeaders(), JSON_UNESCAPED_UNICODE),
                    'body'         => $response->getBody()->getContents(),
                    'status'       => $response->getStatusCode(),
                    'request_time' => $requestTime,
                    'subject_id'   => $uuid ? SubjectUtils::getSubjectId() : 1,
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
            // Limit the number of retries to MAX_RETRIES
            if ($retries && $retries > self::MAX_RETRIES) {
                return false;
            }

            $uuid = SubjectUtils::getUUIDNoException() ?: 0;

            if ($this->isServerError($response)
                || $this->isConnectError($exception)
                || $this->isInvalidResponse($response)
            ) {
                if (config('app.log.third_api')) {
                    $logJob = new LogJob("logThirdPart", [
                        'uuid'       => $uuid,
                        "tag"        => $this->slug,
                        "action"     => 'Retry请求',
                        "method"     => $request->getMethod(),
                        "url"        => $request->getUri(),
                        "headers"    => json_encode($request->getHeaders(), JSON_UNESCAPED_UNICODE),
                        "body"       => \GuzzleHttp\json_encode([
                            "request"  => $request->getBody()->getContents(),
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
     *
     *
     * 标准的json请求使用,即parseJSON()方法使用
     *
     * 检查响应,并返回
     *
     * 不同的实现需要重写此方法
     * Check the array data errors, and Throw exception when the contents contains error.
     *
     * @param array $contents
     *
     * @return array
     * @throws ThirdPartException
     */
    abstract protected function checkAndThrow(
        array $contents
    );


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
            && ! $this->inExceptArray($request);
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
                [ $methods, $except ] = explode(':', $except);
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

}
