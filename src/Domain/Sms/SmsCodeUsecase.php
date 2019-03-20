<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\Sms;

use Mallto\Tool\Data\SmsCode;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2019/3/20
 * Time: 6:14 PM
 */
class  SmsCodeUsecase
{
    public function create($mobile, $code,$subjectId)
    {
        SmsCode::create([
            "mobile" => $mobile,
            "code"   => $code,
            "subject_id"=>$subjectId
        ]);
    }


}