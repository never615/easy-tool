<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Api;


use Illuminate\Routing\Controller;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Data\PageBanner;

/**
 * Created by PhpStorm.
 * User: never615
 * Date: 20/04/2017
 * Time: 11:45 AM
 */
class PageBannerController extends Controller
{
    public function index()
    {
        $subjectId = SubjectUtils::getSubjectId();

        return PageBanner::where("subject_id", $subjectId)
            ->orderBy("weight", "desc")
            ->get();
    }

}
