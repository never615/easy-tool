<?php


namespace Mallto\Tool\Domain\Net;


use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Promise;
use Illuminate\Support\Collection;
use Mallto\Tool\Domain\Log\Logger;
use Mallto\Tool\Exception\InternalHttpException;
use Mallto\Tool\Exception\ThirdPartException;
use Mallto\Tool\Utils\SubjectUtils;
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
     * @var \EasyWeChat\Core\Http
     */
    protected $http;


    const GET = 'get';
    const POST = 'post';
    const JSON = 'json';


    protected $SETTING_KEY_BASE_URL;
    const MAX_RETRIES = 2;

    /**
     * @var Logger
     */
    protected $logger;

    protected $slug;


    protected $baseUrl;


    /**
     * AbstractAPI constructor.
     *
     * @param Logger $logger
     */
    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }


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
     * @return \EasyWeChat\Core\Http
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
            $this->logger->logThirdPart($this->slug, '请求'.$request->getMethod().":".$request->getUri(),
                $request->getBody()->getContents());
//            $this->logger->logThirdPart($this->slug, '请求头'.$request->getMethod().":".$request->getUri(),
//                json_encode($request->getHeaders(), JSON_UNESCAPED_UNICODE));
        }, function (RequestInterface $request, $options, Promise $response) {
            $response->then(function (ResponseInterface $response) use ($request) {
                $this->logger->logThirdPart($this->slug, '响应'.$request->getMethod().":".$request->getUri(),
                    json_encode([
                        'Status'  => $response->getStatusCode(),
                        'Reason'  => $response->getReasonPhrase(),
                        'Headers' => $response->getHeaders(),
                        'Body'    => strval($response->getBody()->getContents()),
                    ], JSON_UNESCAPED_UNICODE));
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

            if (!($this->isServerError($response) || $this->isConnectError($exception))) {
                return false;
            }


            $this->logger->logThirdPart($this->slug.' - Retry', $request->getMethod().":".$request->getUri(),
                \GuzzleHttp\json_encode([
                    "retries:"    => $retries + 1,
                    "max_retries" => self::MAX_RETRIES,
                    "response"    => $response ? 'status code: '.$response->getStatusCode() : $exception->getMessage(),
                ], true));


            return true;
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
        return $exception instanceof ConnectException;
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
