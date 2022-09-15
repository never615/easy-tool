<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

/**
 * Created by PhpStorm.
 * User: never615
 * Date: 07/07/2017
 * Time: 12:25 PM
 */

namespace Mallto\Tool\Domain;

use Mallto\Admin\Data\SubjectSetting;
use Mallto\Admin\Exception\SubjectNotFoundException;
use Mallto\Admin\SubjectSettingUtils;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\SubjectSettingConstants;
use Mallto\Tool\SubjectConfigConstants;
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
 * @package Mallto\Mall\Domain
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
    public static function makeSmsOperator($subjectId)
    {
        if ($subjectId) {
            $operatorSlug = SubjectSettingUtils::getSubjectSetting(SubjectConfigConstants::SMS_SYSTEM,
                $subjectId,'aliyun');
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

        throw new SubjectNotFoundException();
    }
}
