<?php
/*
 * Copyright (c) 2024. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Data\Tag;

/**
 * User: never615 <never615.com>
 * Date: 2024/4/23
 * Time: 16:18
 */
class TagController extends Controller
{

    public function index(Request $request)
    {
        $type = $request->get('type');
        $name = $request->get('name');
        $perPage = $request->get('per_page', 20);

        $subjectId = SubjectUtils::getSubjectId();
        $query = Tag::query()->where("subject_id", $subjectId);

//        $query->select(['id','name', 'type', 'logo', 'created_at', 'updated_at']);

        if ($type) {
            $query->where('type', $type);
        }

        if ($name) {
            $query->where('name', 'ilike', "%$name%");
        }

        $query->orderBy('weight', 'desc')
            ->orderBy('id', 'desc');

        $tags = $query->paginate($perPage);

        return $tags;
    }


    public function show($id)
    {
        $subjectId = SubjectUtils::getSubjectId();
        $tag = Tag::query()->where("subject_id", $subjectId)->find($id);

        return $tag;
    }


    public function store(Request $request)
    {
        $this->validate($request, [
            'type' => 'required|string',
            'name' => 'required|string',
        ]);

        $subjectId = SubjectUtils::getSubjectId();
        $tag = new Tag();
        $tag->fill($request->only([
            'name',
            'type',
            'logo',
        ]));
        $tag->subject_id = $subjectId;
        $tag->save();

        return $tag;
    }


    public function update(Request $request, $id)
    {
        $subjectId = SubjectUtils::getSubjectId();
        $tag = Tag::query()->where("subject_id", $subjectId)->find($id);
        $tag->fill($request->only([
            'name',
            'type',
            'logo',
        ]));
        $tag->save();

        return $tag;
    }


    public function destroy($id)
    {
        $subjectId = SubjectUtils::getSubjectId();
        $tag = Tag::query()->where("subject_id", $subjectId)->findOrFail($id);
        $tag->delete();

        return response()->noContent();
    }

}