<?php
/**
 * Copyright (c) 2022. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain;

use Mallto\Tool\Domain\Sms\AliyunSms;
use Mallto\Tool\Domain\Sms\FusionSms;
use Mallto\Tool\Exception\PermissionDeniedException;

/**
 * 动态注入
 *
 * AdminDeferServiceProvider 提供了注入,
 *
 * 这个最开始是给测试写的,一些情况下,如命令,任务也需要这种方式进行对象创建
 *
 * Class DynamicInject
 *
 */
class DynamicInject
{

    /**
     * 动态注入短信系统
     *
     * @param $subjectId
     *
     * @return mixed|null
     */
    public static function makeSmsOperator()
    {
        $operatorSlug = config('other.sms_system', 'aliyun');
        //$operatorSlug = SubjectSettingUtils::getSubjectSetting(SubjectConfigConstants::SMS_SYSTEM,
        //    $subjectId, config('other.sms_system', 'aliyun'));

        switch ($operatorSlug) {
            case 'aliyun':
                $operator = resolve(AliyunSms::class);
                break;
            case 'fusion':
                $operator = resolve(FusionSms::class);
                break;
            case 'none':
                return null;
            default:
                throw new PermissionDeniedException('无效的短信系统');
        }

        return $operator;
    }
}
