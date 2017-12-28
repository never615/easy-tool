<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Api;


use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Mallto\Tool\Data\Feedback;
use Mallto\Tool\Utils\SubjectUtils;

class FeedbackController extends Controller
{

    public function store(Request $request)
    {
        $this->validate($request, [
            "mobile"  => 'required',
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
