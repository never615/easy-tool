<?php
/**
 * 由 PhpStorm 创建。
 * 作者： never615 <never615.com>
 * 日期： 18/01/2018
 * 时间： 5:36 下午
 */
return [
    'aliyun_mail' => [
        'AccessKeyId'    => env('ALIYUN_MAIL_KEY'),
        'AccessSecret'   => env('ALIYUN_MAIL_SECRET'),
        'ReplyToAddress' => env('ALIYUN_MAIL_REPLY', 'true'),
        'AddressType'    => env('ALIYUN_MAIL_ADDRESS_TYPE', '0'),
    ],
];
