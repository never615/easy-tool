<?php

namespace Mallto\Tool\Domain\Net;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use Mallto\Tool\Exception\ThirdPartException;
use Psr\Http\Message\ResponseInterface;

/**
 * Class Http.
 */
class Http
{

    /**
     * Used to identify handler defined by client code
     * Maybe useful in the future.
     */
    const USER_DEFINED_HANDLER = 'userDefined';

    /**
     * Http client.
     *
     * @var HttpClient
     */
    protected $client;

    /**
     * The middlewares.
     *
     * @var array
     */
    protected $middlewares = [];

    /**
     * Guzzle client default settings.
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
     * Set guzzle default settings.
     *
     * @param array $defaults
     */
    public static function setDefaultOptions($defaults = [])
    {
        self::$defaults = $defaults;
    }


    /**
     * Return current guzzle default settings.
     *
     * @return array
     */
    public static function getDefaultOptions()
    {
        return self::$defaults;
    }


    /**
     * GET request.
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
     * POST request.
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
     * JSON request.
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
        $mothod = 'POST'
    ) {
        is_array($options) && $options = json_encode($options, $encodeOption);

        return $this->request($url, $mothod,
            array_merge(
                [
                    'query'   => $queries,
                    'body'    => $options,
                    'headers' => [ 'content-type' => 'application/json' ],
                ],
                $other));
    }


    /**
     * Upload file.
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
     * Set GuzzleHttp\Client.
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
     * Return GuzzleHttp\Client instance.
     *
     * @return \GuzzleHttp\Client
     */
    public function getClient()
    {
        if ( ! ($this->client instanceof HttpClient)) {
            $this->client = new HttpClient();
        }

        return $this->client;
    }


    /**
     * Add a middleware.
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
     * Return all middlewares.
     *
     * @return array
     */
    public function getMiddlewares()
    {
        return $this->middlewares;
    }


    /**
     * Make a request.
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

        // XXX: json maybe contains special chars. So, let's FUCK the WeChat API developers ...
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
     * Filter the invalid JSON string.
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
     * Build a handler.
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
