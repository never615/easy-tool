<?php declare(strict_types=1);
/**
 * Copyright (c) 2022. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Log;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\Curl\Util;
use Monolog\Handler\MissingExtensionException;
use Monolog\Logger;
use Monolog\Utils;

/**
 * Sends dingding notifications through Webhooks
 *
 * Created by PhpStorm.
 * User: never615
 * Date: 02/11/2022
 * Time: 12:25 PM
 */
class DingdingWebhookHandler extends AbstractProcessingHandler
{

    /**
     * Slack Webhook token
     *
     * @var string
     */
    private $webhookUrl;

    private string $secret;

    private string $unique;

    private string $accessToken;


    /**
     * @param string $url Webhook URL
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


    public function getWebhookUrl(): string
    {
        return $this->webhookUrl;
    }


    /**
     * {@inheritDoc}
     */
    protected function write(array $record): void
    {
        $postData = [
            'msgtype' => "text",
            'text'    => [
                'content' => array_merge(
                    [
                        'unique' => $this->unique,
                    ],
                    array_except($record, [
                        'formatted',
                        'extra',
                        'level',
                    ])),
            ],
        ];

        $postString = Utils::jsonEncode($postData);

        //sign
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
