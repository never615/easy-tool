<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Api;


use Mallto\Admin\SubjectUtils;
use Illuminate\Http\Request;
use Mallto\Tool\Data\AdImage;

/**
 * Created by PhpStorm.
 * User: never615
 * Date: 20/04/2017
 * Time: 11:45 AM
 */
class HeadImageController extends \App\Http\Controllers\Controller
{
    public function index(Request $request)
    {

        $this->validate($request, [
            "type" => "required",
        ]);


        $subjectId = SubjectUtils::getSubjectId();

        return AdImage::where("subject_id", $subjectId)
            ->where("type", $request->type)
            ->first();
    }

}
