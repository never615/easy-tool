<?php declare(strict_types=1);
/**
 * Copyright (c) 2022. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Log;

use Mallto\Tool\Domain\DynamicInject;
use Mallto\Tool\Utils\ConfigUtils;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\MissingExtensionException;
use Monolog\Logger;
use Monolog\Utils;

/**
 * Sends fusion sms notifications
 *
 * Created by PhpStorm.
 * User: never615
 * Date: 02/11/2022
 * Time: 12:25 PM
 */
class FusionSmsHandler extends AbstractProcessingHandler
{

    private string $unique;


    /**
     * @param string $unique
     * @param int    $level
     * @param bool   $bubble
     *
     * @throws MissingExtensionException
     */
    public function __construct(
        string $unique = '',
        $level = Logger::CRITICAL,
        bool $bubble = true
    ) {
        if ( ! extension_loaded('curl')) {
            throw new MissingExtensionException('The curl extension is needed to use the SimpleWebhookHandler');
        }

        parent::__construct($level, $bubble);

    }


    /**
     * {@inheritDoc}
     */
    protected function write(array $record): void
    {
        $postData =
            array_merge(
                [
                    'unique' => $this->unique,
                ],
                array_except($record, [
                    'formatted',
                    'extra',
                    'level',
                ]));

        $postString = Utils::jsonEncode($postData);

        //获取模板ID
        $smsTemplateCode = ConfigUtils::get('log_sms_template_code', 'API-ZWX-00001');
        //拼接短信内容
        $content = $postString;
        //短信系统注入
        $sms = DynamicInject::makeSmsOperator();

        $mobiles = [];
        $mobilesStr = ConfigUtils::get('system_alarm_contact');
        if ($mobilesStr) {
            $mobiles = explode(',', $mobilesStr);
        }

        $sms->sendSms(
            $mobiles,
            $smsTemplateCode,
            null, null, $content);
    }

}
