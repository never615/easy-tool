<?php
/*
 * Copyright (c) 2024. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

        $this->validate($request, [
            'type' => 'sometimes|string',
            'name' => 'sometimes|string',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'en_name' => 'sometimes|string',
            'tc_name' => 'sometimes|string',
            'slug' => 'sometimes|string',
            'third_id' => 'sometimes|string',
        ]);

        $subjectId = SubjectUtils::getSubjectId();
        $query = Tag::query()->where("subject_id", $subjectId);

//        $query->select(['id','name', 'type', 'logo', 'created_at', 'updated_at']);

        if ($type) {
            $query->where('type', $type);
        }

        if ($name) {
            $query->where('name', 'ilike', "%$name%");
        }

        if ($request->get('en_name')) {
            $query->where('en_name', 'ilike', "%{$request->get('en_name')}%");
        }

        if ($request->get('tc_name')) {
            $query->where('tc_name', 'ilike', "%{$request->get('tc_name')}%");
        }

        if ($request->get('third_id')) {
            $query->where('third_id', 'ilike', "%{$request->get('third_id')}%");
        }

        if($request->get('slug')) {
            $query->where('slug', 'ilike', "%{$request->get('slug')}%");
        }

        $query->orderBy('weight', 'desc')
            ->orderBy('id', 'desc');

        $tags = $query->paginate($perPage);

        return $tags;
    }


    public function show($id)
    {
        $subjectId = SubjectUtils::getSubjectId();
        return Tag::query()->where("subject_id", $subjectId)->findOrFail($id);
    }


    public function store(Request $request)
    {
        $this->validate($request, [
            'type' => 'required|string',
            'name' => 'required|string',
            'en_name' => 'sometimes|string',
            'tc_name' => 'sometimes|string',
            'third_id' => 'sometimes|string',
            'slug' => 'sometimes|string',
        ]);

        $subjectId = SubjectUtils::getSubjectId();
        $tag = new Tag();
        $tag->fill($request->only([
            'name',
            'type',
            'logo',
            'slug',
            'en_name',
            'tc_name',
            'third_id',
        ]));
        $tag->subject_id = $subjectId;
        $tag->save();

        return $tag;
    }


    public function update(Request $request, $id)
    {
        $subjectId = SubjectUtils::getSubjectId();
        $tag = Tag::query()->where("subject_id", $subjectId)->findOrFail($id);

        $aa=$request->only([
            'name',
            'type',
            'logo',
            'slug',
            'en_name',
            'tc_name',
            'third_id',
        ]);
//        Log::debug($aa);

        $tag->fill($request->only([
            'name',
            'type',
            'logo',
            'slug',
            'en_name',
            'tc_name',
            'third_id',
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