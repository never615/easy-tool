<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Controller\Api;

use Illuminate\Http\Request;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Data\Ad;

/**
 * 由 PhpStorm 创建。
 * 作者： never615
 * 日期： 20/04/2017
 * 时间： 11:45 上午
 */
class AdController extends \App\Http\Controllers\Controller
{

    /**
     * 处理「index」相关的内部协作步骤。
     */
    public function index(Request $request)
    {
        $subjectId = SubjectUtils::getSubjectId();

        return Ad::with("ad_images")
            ->where("subject_id", $subjectId)
            ->where("type", $request->get("type"))
            ->where("ad_type", $request->get("ad_type"))
            ->where("switch", true)
            ->orderBy("created_at", "desc")
            ->firstOrFail();
    }

}
