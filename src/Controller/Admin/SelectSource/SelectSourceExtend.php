<?php
/**
 * Copyright (c) 2021. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin\SelectSource;

use Illuminate\Support\Facades\DB;
use Mallto\Admin\Controllers\Base\SelectSourceExtendInterface;
use Mallto\Tool\Data\Ad;
use Mallto\Tool\Data\PagePvManager;
use Mallto\Tool\Data\Tag;

/**
 * User: never615 <never615.com>
 * Date: 2021/2/7
 * Time: 1:56 上午
 */
class SelectSourceExtend implements SelectSourceExtendInterface
{

    /**
     * 方便下级依赖库添加数据源
     *
     * @param $key
     * @param $id
     * @param $childSubjectIds
     * @param $q
     * @param $perPage
     * @param $adminUser
     * @param $fatherValue
     */
    public function addDataSource($key, $id, $childSubjectIds, $q, $perPage, $adminUser, $fatherValue)
    {
        if ($key === 'ad_types') {
            $pagePvManager = PagePvManager::query()
                ->whereIn("subject_id", $childSubjectIds)
                ->where("path", $q)
                ->first();

            if ($pagePvManager) {
                $adTypes = $pagePvManager->ad_types;

                $adTypes = array_unique(array_merge($adTypes, ["float_image"]));

                $adTypes = array_only(Ad::AD_TYPE, $adTypes);

                $temps = [];
                foreach ($adTypes as $adTypesKey => $value) {
                    $temps[] = [
                        "id" => $adTypesKey,
                        "text" => $value,
                    ];
                }

                return $temps;
            } else {
                return [];
            }

        }


        if ($key === 'tag') {
            if (!is_null($id)) {
                $id = explode(",", $id);

                return Tag::query()
                    ->select(DB::raw("id,name as text"))
                    ->findOrFail($id);
            } else {
                if (count($childSubjectIds) > 1) {
                    $query = Tag::query()
                        ->select(DB::raw("tags.id,tags.name||'-('||subjects.name||')' as text"))
                        ->join('subjects', 'subjects.id', 'subject_id')
                        ->whereIn("tags.subject_id", $childSubjectIds)
                        ->orderBy('tags.created_at', 'desc');

                    $query->where('tags.name', '~*', "$q");

                    return $query->paginate($perPage, ['id', 'text']);
                } else {
                    $query = Tag::query()
                        ->select(DB::raw('tags.id,tags.name as text'))
                        ->join('subjects', 'subjects.id', 'subject_id')
                        ->whereIn("tags.subject_id", $childSubjectIds)
                        ->orderBy('tags.created_at', 'desc');

                    $query->where('tags.name', '~*', "$q");

                    return $query->paginate($perPage, ['id', 'text']);
                }
            }
        }

    }


    /**
     * 方便下级依赖库添加数据源
     *
     * @param $q
     * @param $perPage
     * @param $childSubjectIds
     * @param $fatherValue
     */
    public function addLoad($q, $perPage, $childSubjectIds, $fatherValue)
    {

    }
}
