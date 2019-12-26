<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Api;

use Illuminate\Http\Request;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Data\Ad;

/**
 * Created by PhpStorm.
 * User: never615
 * Date: 20/04/2017
 * Time: 11:45 AM
 */
class AdController extends \App\Http\Controllers\Controller
{

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
