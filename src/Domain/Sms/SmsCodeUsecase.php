<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Sms;

use Illuminate\Support\Facades\Request;
use Mallto\Tool\Data\SmsCode;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2019/3/20
 * Time: 6:14 PM
 */
class  SmsCodeUsecase
{

    /**
     * @param      $mobile
     * @param      $code
     * @param      $subjectId
     * @param null $appId 第三方请求者的appId
     */
    public function create($mobile, $code, $subjectId, $appId = null)
    {
        if ( ! $appId) {
            $appId = Request::header("app_id");
        }
        SmsCode::create([
            "mobile"     => $mobile,
            "code"       => $code,
            "subject_id" => $subjectId,
            'app_id'     => $appId,
        ]);
    }

}
