<?php
/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 18/01/2018
 * Time: 5:36 PM
 */
return [
    'aliyun_mail' => [
        'AccessKeyId'    => env('ALIYUN_MAIL_KEY'),
        'AccessSecret'   => env('ALIYUN_MAIL_SECRET'),
        'ReplyToAddress' => env('ALIYUN_MAIL_REPLY','true'),
        'AddressType' => env('ALIYUN_MAIL_ADDRESS_TYPE','0'),
    ],
];