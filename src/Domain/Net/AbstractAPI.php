<?php


namespace Mallto\Tool\Domain\Net;


use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Promise;
use Illuminate\Support\Collection;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Exception\InternalHttpException;
use Mallto\Tool\Exception\ThirdPartException;
use Mallto\Tool\Jobs\LogJob;
use Mallto\Tool\Utils\AppUtils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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


    protected $SETTING_KEY_BASE_URL;
    const MAX_RETRIES = 2;

    protected $slug;


    protected $baseUrl;


    /**
     * 获取请求的基础url,url后已经拼接好/
     *
     * @param $subject
     * @return mixed
     */
    protected function getBaseUrl($subject)
    {
        if (empty($this->SETTING_KEY_BASE_URL)) {
            throw new InternalHttpException("未设置SETTING_KEY_BASE_URL");
        }

        $this->baseUrl = SubjectUtils::getSubectConfig($subject, $this->SETTING_KEY_BASE_URL);

        return rtrim($this->baseUrl, '/').'/';
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
        if (is_null($this->http)) {
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

        $contents = $http->parseJSON(call_user_func_array([$http, $method], $args));

        $this->checkAndThrow($contents);

        return new Collection($contents);
    }


    /**
     * Register Guzzle middlewares.
     */
    protected function registerHttpMiddlewares()
    {
        // log
        $this->http->addMiddleware($this->logMiddleware());
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
        return Middleware::tap(function (RequestInterface $request, $options) {
            dispatch(new LogJob("logThirdPart",[
                "tag"     => $this->slug,
                "action"  => '请求',
                "method"  => $request->getMethod(),
                "url"     => $request->getUri(),
                "headers" => json_encode($request->getHeaders(), JSON_UNESCAPED_UNICODE),
                "body"    => json_encode(AppUtils::httpQueryBuildReverse($request->getBody()->getContents()),
                    JSON_UNESCAPED_UNICODE),
            ]));
        }, function (RequestInterface $request, $options, Promise $response) {
            $response->then(function (ResponseInterface $response) use ($request) {
                dispatch(new LogJob("logThirdPart",[
                    "tag"     => $this->slug,
                    "action"  => '响应',
                    "method"  => $request->getMethod(),
                    "url"     => $request->getUri(),
                    "headers" => json_encode($request->getHeaders(), JSON_UNESCAPED_UNICODE),
                    "body"    => $response->getBody()->getContents(),
                    "status"  => $response->getStatusCode(),
                ]));
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
            $exception
        ) {
            // Limit the number of retries to MAX_RETRIES
            if ($retries && $retries > self::MAX_RETRIES) {
                return false;
            }


            if ($this->isServerError($response) || $this->isConnectError($exception)) {
                dispatch(new LogJob("logThirdPart",[
                    "tag"     => $this->slug,
                    "action"  => 'Retry请求',
                    "method"  => $request->getMethod(),
                    "url"     => $request->getUri(),
                    "headers" => json_encode($request->getHeaders(), JSON_UNESCAPED_UNICODE),
                    "body"    => \GuzzleHttp\json_encode([
                        "request"  => $request->getBody()->getContents(),
                        "response" => $response ? 'status code: '.$response->getStatusCode() : ($exception ? $exception->getMessage() : ""),
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
     * @return bool
     */
    protected function isServerError(ResponseInterface $response = null)
    {
        return $response && $response->getStatusCode() >= 500;
    }

    /**
     * @param RequestException $exception
     * @return bool
     */
    protected function isConnectError(RequestException $exception = null)
    {
        if ($exception && (strpos($exception->getMessage(), ' Connection reset by peer'))) {
//            \Log::warning("基础网络库:".$exception->getMessage());
            if ($exception instanceof ConnectException) {
                \Log::warning("基础网络库:reset by peer属于ConnectException");
            }

            return true;
        } else {
            if ($exception instanceof ConnectException) {
                \Log::warning("基础网络库:".$exception->getMessage());

                return false;
            } else {
                if ($exception) {
                    \Log::warning("基础网络库:其他异常");
                    \Log::warning($exception->getMessage());
                    \Log::warning($exception->getTraceAsString());
                }

                return false;
            }
        }
    }

    /**
     * 不同的实现需要重写此方法 标准的json请求使用
     * Check the array data errors, and Throw exception when the contents contains error.
     *
     * @param array $contents
     *
     * @return array
     * @throws ThirdPartException
     */
    protected abstract function checkAndThrow(
        array $contents
    );

}
