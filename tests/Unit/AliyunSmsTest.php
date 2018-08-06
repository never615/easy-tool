<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Tests\Unit;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Mallto\Tool\Domain\Sms\AliyunSms;
use Tests\CreatesApplication;


class AliyunSmsTest extends BaseTestCase
{
    use CreatesApplication;


//    public function testSendSms()
//    {
//        $sms = app(AliyunSms::class);
//        $result = $sms->sendSms('17620358615', "墨兔", "SMS_141255069", [
//            "code" => 1111,
//        ]);
//
//        $this->assertTrue($result);
//    }


    public function testSendBatchSms()
    {
        $sms = app(AliyunSms::class);
        $result = $sms->sendBatchSms([
//            "17620358615",
            "13035810099",
        ], [
            "墨兔",
//            "墨兔",
        ], "SMS_141195417",
            [
                [
                    "code" => 1111,
                ],
//                [
//                    "code" => 1111,
//                ],
            ]);
        $this->assertTrue($result);
    }

}
