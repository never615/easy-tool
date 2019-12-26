<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Net;

use GuzzleHttp\Client;
use Mallto\Tool\Domain\Log\Logger;
use Mallto\Tool\Exception\ThirdPartConnectException;
use Mallto\Tool\Exception\ThirdPartException;

/**
 * @deprecated
 * Created by PhpStorm.
 * User: never615
 * Date: 06/07/2017
 * Time: 3:45 PM
 */
trait BasicClientTrait
{

    /**
     * 基本
     *
     * @param        $url
     * @param        $data
     * @param string $requestType
     *
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @deprecated
     */
    protected function basicClient($url, $data, $requestType = 'post')
    {
        $logger = app(Logger::class);

        $logger->logThirdPart(self::SLUG, '请求:' . $url, json_encode($data, JSON_UNESCAPED_UNICODE));
        $client = new Client([
            'base_uri' => $this->baseUrl,
        ]);
        $resultArr = null;

        try {
            $response = $client->request($requestType, $url, $data);
            $body = $response->getBody();

            $logger->logThirdPart(self::SLUG, '返回:' . $url, $body);

            return $this->resultHandler($body);
        } catch (ThirdPartException $e) {
            throw $e;
        } catch (\Exception $e) {
            //RequestException

            $logger->logThirdPart(self::SLUG, 'http异常',
                'code:' . $e->getCode() . '--msg:' . $e->getMessage());
//            \Log::warning($e);
            throw new ThirdPartConnectException(self::SLUG . ":服务连接异常");
        }
    }


    /**
     * 自定义处理响应结果
     *
     * @param $body
     *
     * @return mixed
     */
    protected abstract function resultHandler($body);
}
