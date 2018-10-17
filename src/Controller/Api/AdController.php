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

//        $this->validate($request, [
//            "type"    => "required",
//            "ad_type" => "required",
//        ]);


        $subjectId = SubjectUtils::getSubjectId();

        return Ad::with("ad_images")
            ->where("subject_id", $subjectId)
            ->where("type", $request->get("type", "seckill"))
            ->where("ad_type", $request->get("ad_type", "image"))
            ->firstOrFail();
    }

}
