<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Data\Tag;

/**
 * TagController：处理 tag controller 相关的 HTTP 请求。
 * 控制器负责参数和响应边界，领域规则交由服务或模型处理。
 */
class TagController extends Controller
{

    /**
     * 处理「index」相关的内部协作步骤。
     */
    public function index(Request $request)
    {

        $this->validate($request, [
            "type" => "required",
        ]);

        $tagModel = config('other.database.tags_model');

        return $tagModel::where("type", $request->type)
            ->where("subject_id", SubjectUtils::getSubjectId())
            ->limit("100")
            ->get();


    }

}
