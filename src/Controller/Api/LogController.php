<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Data\Log;
use Mallto\Tool\Utils\AppUtils;
use Mallto\User\Data\UserAuth;

/**
 * User: never615 <never615.com>
 * Date: 2019-09-23
 * Time: 14:59
 */
class LogController extends Controller
{

    public function store(Request $request)
    {

        $this->validate($request, [
            "tag"  => 'required',
            "data" => "required",
        ]);

        $subjectId = SubjectUtils::getSubjectId();

        $userId = null;
        $openid = null;

        if ($request->openid) {
            $openid = AppUtils::getOpenidFromOriginalOpenid($request->openid);
            //查询对应的user_id
            $userAuth = UserAuth::where([
                "subject_id"    => $subjectId,
                "identity_type" => "wechat",
                "identifier"    => $openid,
            ])->first();

            if ($userAuth) {
                $userId = $userAuth->user_id;
            }
        }

        Log::create([
            "subject_id" => $subjectId,
            "tag"        => $request->tag,
            "data"       => $request->data,
            "user_id"    => $userId ?? $request->user_id,
            "user_uuid"  => $openid ?? $request->user_uuid,
        ]);

        return response()->noContent();
    }
}
