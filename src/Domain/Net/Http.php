<?php

namespace Mallto\Tool\Domain\Net;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use Mallto\Tool\Exception\ThirdPartException;
use Psr\Http\Message\ResponseInterface;

/**
 * Http. 类
 */
class Http
{

    /**
     * 用于标识客户端代码定义的处理器
     * 未来可能有用。
     */
    const USER_DEFINED_HANDLER = 'userDefined';

    /**
     * HTTP 客户端。
     *
     * @var HttpClient
     */
    protected $client;

    /**
     * 中间件列表。
     *
     * @var array
     */
    protected $middlewares = [];

    /**
     * Guzzle 客户端默认设置。
     *
     * @var array
     */
    protected static $defaults = [
        'curl' => [
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ],
//        'http_errors' => false,
    ];


    /**
     * 设置 Guzzle 默认配置。
     *
     * @param array $defaults
     */
    public static function setDefaultOptions($defaults = [])
    {
        self::$defaults = $defaults;
    }


    /**
     * 返回当前 Guzzle 默认设置。
     *
     * @return array
     */
    public static function getDefaultOptions()
    {
        return self::$defaults;
    }


    /**
     * GET 请求。
     *
     * @param string $url
     * @param array  $options
     *
     * @param        $otherOptions
     *
     * @return ResponseInterface
     *
     */
    public function get($url, array $options = [], $otherOptions = [])
    {
        return $this->request($url, 'GET', array_merge([ 'query' => $options ], $otherOptions));
    }


    /**
     * POST 请求。
     *
     * @param string       $url
     * @param array|string $options
     *
     * @param              $other
     *
     * @return ResponseInterface
     */
    public function post($url, $options = [], $other = [])
    {
        $key = is_array($options) ? 'form_params' : 'body';

        return $this->request($url, 'POST', array_merge(
            [ $key => $options ],
            $other));
    }


    /**
     * JSON 请求。
     *
     * @param string       $url
     * @param string|array $options
     * @param int          $encodeOption
     *
     * @param array        $queries
     * @param array        $other
     * @param string       $mothod
     *
     * @return ResponseInterface
     */
    public function json(
        $url,
        $options = [],
        $encodeOption = JSON_UNESCAPED_UNICODE,
        $queries = [],
        $other = [],
        $headers = [],
        $mothod = 'POST'
    ) {
        is_array($options) && $options = json_encode($options, $encodeOption);

        return $this->request($url, $mothod,
            array_merge(
                [
                    'query'   => $queries,
                    'body'    => $options,
                    'headers' => array_merge([ 'content-type' => 'application/json' ], $headers),
                ],
                $other));
    }


    /**
     * 上传文件。
     *
     * @param string $url
     * @param array  $files
     * @param array  $form
     *
     * @return ResponseInterface
     *
     * @throws ThirdPartException
     */
    public function upload($url, array $files = [], array $form = [], array $queries = [])
    {
        $multipart = [];

        foreach ($files as $name => $path) {
            $multipart[] = [
                'name'     => $name,
                'contents' => fopen($path, 'r'),
            ];
        }

        foreach ($form as $name => $contents) {
            $multipart[] = compact('name', 'contents');
        }

        return $this->request($url, 'POST', [ 'query' => $queries, 'multipart' => $multipart ]);
    }


    /**
     * 设置 GuzzleHttp\Client。
     *
     * @param \GuzzleHttp\Client $client
     *
     * @return Http
     */
    public function setClient(HttpClient $client)
    {
        $this->client = $client;

        return $this;
    }


    /**
     * 返回 GuzzleHttp\Client 实例。
     *
     * @return \GuzzleHttp\Client
     */
    public function getClient()
    {
        if ( ! ($this->client instanceof HttpClient)) {
            $this->client = new HttpClient(
                [
                    // LaravelS 常驻 Worker：timeout 直接占用 Worker 进程。
                    // 配合 API::MAX_RETRIES=1，最坏耗时 = 8 + 0.5(delay) + 8 = 16.5s
                    // 原来 timeout=10 + MAX_RETRIES=2（实际3次retry）最坏 47s，是 Worker 死锁根因。
                    'timeout'         => 8,
                    'connect_timeout' => 5,
                ]
            );
        }

        return $this->client;
    }


    /**
     * 添加中间件。
     *
     * @param callable $middleware
     *
     * @return $this
     */
    public function addMiddleware(callable $middleware)
    {
        array_push($this->middlewares, $middleware);

        return $this;
    }


    /**
     * 返回全部中间件。
     *
     * @return array
     */
    public function getMiddlewares()
    {
        return $this->middlewares;
    }


    /**
     * 发起请求。
     *
     * @param string $url
     * @param string $method
     * @param array  $options
     *
     * @return ResponseInterface
     *
     * @throws ThirdPartException
     */
    public function request($url, $method = 'GET', $options = [])
    {
        $method = strtoupper($method);

        $options = array_merge(self::$defaults, $options);

        $options['handler'] = $this->getHandler();

        return $this->getClient()->request($method, $url, $options);
    }


    /**
     * @param \Psr\Http\Message\ResponseInterface|string $body
     *
     * @return mixed
     *
     * @throws ThirdPartException
     */
    public function parseJSON($body)
    {
        if ($body instanceof ResponseInterface) {
            $body = $body->getBody();
        }

        // 注意：JSON 可能包含特殊字符；此处对微信接口的相关设计表达强烈不满。
        $body = $this->fuckTheWeChatInvalidJSON($body);

        if (empty($body)) {
            return false;
        }

        $contents = json_decode($body, true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new ThirdPartException('Failed to parse JSON: ' . json_last_error_msg());
        }

        return $contents;
    }


    /**
     * 过滤无效的 JSON 字符串。
     *
     * @param \Psr\Http\Message\StreamInterface|string $invalidJSON
     *
     * @return string
     */
    protected function fuckTheWeChatInvalidJSON($invalidJSON)
    {
        return preg_replace('/[\x00-\x1F\x80-\x9F]/u', '', trim($invalidJSON));
    }


    /**
     * 构建处理器。
     *
     * @return HandlerStack
     */
    protected function getHandler()
    {
        $stack = HandlerStack::create();

        foreach ($this->middlewares as $middleware) {
            $stack->push($middleware);
        }

        if (isset(static::$defaults['handler']) && is_callable(static::$defaults['handler'])) {
            $stack->push(static::$defaults['handler'], self::USER_DEFINED_HANDLER);
        }

        return $stack;
    }
}
