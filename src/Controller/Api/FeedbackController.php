<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Data\Feedback;

/**
 * FeedbackController：处理 feedback controller 相关的 HTTP 请求。
 * 控制器负责参数和响应边界，领域规则交由服务或模型处理。
 */
class FeedbackController extends Controller
{

    /**
     * 处理「store」相关的内部协作步骤。
     */
    public function store(Request $request)
    {
        $this->validate($request, [
//            "mobile"  => 'required',
            "content" => "required",
        ]);

        Feedback::create([
            "mobile"     => $request->mobile,
            "content"    => $request->get("content"),
            "subject_id" => SubjectUtils::getSubjectId(),
        ]);

        return response()->nocontent();
    }

}
