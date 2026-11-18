<?php declare(strict_types=1);
/**
 * 版权所有 (c) 2022. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Log;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\Curl\Util;
use Monolog\Handler\MissingExtensionException;
use Monolog\Logger;
use Monolog\LogRecord;
use Monolog\Utils;

/**
 * 通过 Webhook 发送钉钉通知
 *
 * 由 PhpStorm 创建。
 * 作者： never615
 * 日期： 02/11/2022
 * 时间： 12:25 下午
 */
class DingdingWebhookHandler extends AbstractProcessingHandler
{

    /**
     * Slack Webhook 令牌
     *
     * @var string
     */
    private $webhookUrl;

    private string $secret;

    private string $unique;

    private string $accessToken;


    /**
     * @param string $url Webhook 地址
     * @param string $accessToken
     * @param string $secret
     * @param string $unique
     * @param int    $level
     * @param bool   $bubble
     *
     * @throws MissingExtensionException
     */
    public function __construct(
        string $token,
        string $secret,
        string $url = 'https://oapi.dingtalk.com/robot/send',
        string $unique = '',
        $level = Logger::CRITICAL,
        bool $bubble = true
    ) {
        if ( ! extension_loaded('curl')) {
            throw new MissingExtensionException('The curl extension is needed to use the SimpleWebhookHandler');
        }

        parent::__construct($level, $bubble);

        $this->webhookUrl = $url;
        $this->secret = $secret;
        $this->unique = $unique;
        $this->accessToken = $token;
    }


    /**
     * 处理「getWebhookUrl」相关的内部协作步骤。
     */
    public function getWebhookUrl(): string
    {
        return $this->webhookUrl;
    }


    /**
     * {@inheritDoc}
     */
    protected function write(LogRecord $record): void
    {
        $postData = [
            'msgtype' => "text",
            'text'    => [
                'content' => array_merge(
                    [
                        'unique' => $this->unique,
                    ],
                    array_except($record->toArray(), [
                        'formatted',
                        'extra',
                        'level',
                    ])),
            ],
        ];

        $postString = Utils::jsonEncode($postData);

        //签名
        $timestamp = time() * 1000;

        $stringToSign = $timestamp . "\n" . $this->secret;

        $sign = base64_encode(hash_hmac('sha256', $stringToSign, $this->secret, true));

        $url = $this->webhookUrl . '?' . http_build_query([
                'access_token' => $this->accessToken,
                'timestamp'    => $timestamp,
                'sign'         => $sign,
            ]);

        $ch = curl_init();
        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [ 'Content-type: application/json' ],
            CURLOPT_POSTFIELDS     => $postString,
        ];

        if (defined('CURLOPT_SAFE_UPLOAD')) {
            $options[CURLOPT_SAFE_UPLOAD] = true;
        }

        curl_setopt_array($ch, $options);

        Util::execute($ch);
    }

}
