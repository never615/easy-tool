<?php
/**
 * 版权所有 (c) 2019. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Domain\Sms;

use Illuminate\Support\Facades\Request;
use Mallto\Tool\Data\SmsCode;

/**
 * 由 PhpStorm 创建。
 * 作者： never615 <never615.com>
 * 日期： 2019/3/20
 * 时间： 6:14 下午
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
