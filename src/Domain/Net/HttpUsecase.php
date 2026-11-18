<?php
/**
 * 版权所有 (c) 2020. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Domain\Net;

use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Promise;
use Illuminate\Support\Collection;
use Mallto\Admin\Data\Subject;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Jobs\LogJob;
use Mallto\Tool\Utils\AppUtils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Illuminate\Support\Facades\Log;

/**
 * 作者： never615 <never615.com>
 * 日期： 2020/4/20
 * 时间： 5:17 下午
 */
class HttpUsecase
{

    /**
     * HTTP 实例。
     *
     * @var
     */
    protected $http;

    const GET = 'get';
    const POST = 'post';
    const JSON = 'json';

    protected $max_retries = 2;

    /**
     * 请求的日志标识
     *
     * @var
     */
    protected $slug = 'default';


    /**
     * @return mixed
     */
    public function getSlug()
    {
        return $this->slug;
    }


    /**
     * @param mixed $slug
     */
    public function setSlug($slug): void
    {
        $this->slug = $slug;
    }


    /**
     * 返回 HTTP 实例。
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
     * 设置 HTTP 实例。
     *
     * @param Http $http
     *
     * @return $this
     */
    public function setHttp(Http $http)
    {
        $this->http = $http;

        return $this;
    }


    /**
     * 解析响应中的 JSON 并检查错误。
     *
     * @param string $method
     * @param array  $args
     *
     * @return Collection
     */
    public function parseJSON($method, array $args)
    {
        $http = $this->getHttp();

        $contents = $http->parseJSON(call_user_func_array([ $http, $method ], $args));

        return new Collection($contents);
    }


    /**
     * 客户端异常日志
     *
     * @param ClientException $clientException
     */
    public function clientExceptionLog(ClientException $clientException)
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
     * 注册 Guzzle 中间件。
     */
    protected function registerHttpMiddlewares()
    {
        // 日志
        $this->http->addMiddleware($this->logMiddleware());
        // 重试
        $this->http->addMiddleware($this->retryMiddleware());
    }


    /**
     * 记录请求日志。
     *
     * @return \Closure
     */
    protected function logMiddleware()
    {
        $requestId = AppUtils::create_uuid();

        $startTime = 0;
        $endTime = 0;

        return Middleware::tap(function (RequestInterface $request, $options) use ($requestId, &$startTime) {
            if ( ! AppUtils::isProduction()) {
                $startTime = microtime(true);
            }
            try {
                dispatch(new LogJob('logThirdPart', [
                    'uuid'       => SubjectUtils::getUUIDNoException() ?: 0,
                    'request_id' => $requestId,
                    'tag'        => $this->slug,
                    'action'     => '请求',
                    'method'     => $request->getMethod(),
                    'url'        => $request->getUri(),
                    'headers'    => json_encode($request->getHeaders(), JSON_UNESCAPED_UNICODE),
                    'body'       => is_null(json_decode($request->getBody())) ? json_encode(AppUtils::httpQueryBuildReverse($request->getBody()),
                        JSON_UNESCAPED_UNICODE) : $request->getBody() . "",
                    'subject_id' => Subject::min('id'),
                ]));
            } catch (\Exception $exception) {
                Log::error("记录第三方方请求日志错误");
                Log::warning($exception);
            }


        }, function (RequestInterface $request, $options, Promise $response) use (
            $requestId,
            &$startTime,
            $endTime
        ) {
            $response->then(function (ResponseInterface $response) use ($request, $requestId, &$startTime) {
                $requestTime = 0;
                if ( ! AppUtils::isProduction()) {
                    $endTime = microtime(true);
                    $requestTime = round($endTime - $startTime, 3);
                }

                dispatch(new LogJob('logThirdPart', [
                    'uuid'         => SubjectUtils::getUUIDNoException() ?: 0,
                    'request_id'   => $requestId,
                    'tag'          => $this->slug,
                    'action'       => '响应',
                    'method'       => $request->getMethod(),
                    'url'          => $request->getUri(),
                    'headers'      => json_encode($request->getHeaders(), JSON_UNESCAPED_UNICODE),
                    'body'         => $response->getBody()->getContents(),
                    'status'       => $response->getStatusCode(),
                    'request_time' => $requestTime,
                    'subject_id'   => Subject::min('id'),
                ]));
            });


        });
    }


    /**
     * 返回重试中间件。
     *
     * @return \Closure
     */
    protected function retryMiddleware()
    {
        return Middleware::retry(function (
            $retries,
            RequestInterface $request,
            ResponseInterface $response = null,
            $exception
        ) {
            // 将重试次数限制为 MAX_RETRIES
            if ($retries && $retries > $this->getMaxRetries()) {
                return false;
            }

            if ($this->isServerError($response) || $this->isConnectError($exception)) {
                dispatch(new LogJob("logThirdPart", [
                    'uuid'    => SubjectUtils::getUUIDNoException() ?: 0,
                    "tag"     => $this->slug,
                    "action"  => 'Retry请求',
                    "method"  => $request->getMethod(),
                    "url"     => $request->getUri(),
                    "headers" => json_encode($request->getHeaders(), JSON_UNESCAPED_UNICODE),
                    "body"    => \GuzzleHttp\json_encode([
                        "request"  => $request->getBody()->getContents(),
                        "response" => $response ? 'status code: ' . $response->getStatusCode() : ($exception ? $exception->getMessage() : ""),
                    ], JSON_UNESCAPED_UNICODE),
                ]));

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
     * @param RequestException $exception
     *
     * @return bool
     */
    protected function isConnectError(RequestException $exception = null)
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
     * @return int
     */
    public function getMaxRetries(): int
    {
        return $this->max_retries;
    }


    /**
     * @param int $max_retries
     */
    public function setMaxRetries(int $max_retries): void
    {
        $this->max_retries = $max_retries;
    }

}
