<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Controller\Api;

use Illuminate\Routing\Controller;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Data\PageBanner;

/**
 * 由 PhpStorm 创建。
 * 作者： never615
 * 日期： 20/04/2017
 * 时间： 11:45 上午
 */
class PageBannerController extends Controller
{

    /**
     * 处理「index」相关的内部协作步骤。
     */
    public function index()
    {
        $subjectId = SubjectUtils::getSubjectId();

        return PageBanner::where("subject_id", $subjectId)
            ->orderBy("weight", "desc")
            ->get();
    }

}
