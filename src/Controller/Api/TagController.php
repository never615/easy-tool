<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Data\Tag;

class TagController extends Controller
{

    public function index(Request $request)
    {

        $this->validate($request, [
            "type" => "required",
        ]);

        return Tag::where("type", $request->type)
            ->where("subject_id", SubjectUtils::getSubjectId())
            ->limit("100")
            ->get();


    }

}
