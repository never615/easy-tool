<?php
/**
 * Copyright (c) 2021. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin\SelectSource;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use LaravelIdea\Helper\Mallto\Tool\Data\_IH_Tag_C;
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
     * @return array|LengthAwarePaginator|Collection|Model|\Illuminate\Pagination\LengthAwarePaginator|_IH_Tag_C|Tag|Tag[]|void|null
     */
    public function addDataSource($key, $id, $childSubjectIds, $q, $perPage, $adminUser, $fatherValue)
    {
        switch ($key) {
            case 'ad_types':
                return $this->adTypesSelect($id, $childSubjectIds, $q, $perPage);
            case 'tag':
                return $this->tagSelect($id, $childSubjectIds, $q, $perPage);
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

    protected function adTypesSelect($id, $childSubjectIds, $q, $perPage)
    {
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

    protected function tagSelect($id, $childSubjectIds, $q, $perPage)
    {
        $tagType = Request::get('tag_type');

        $query = Tag::query();

        $query->join('subjects', 'subjects.id', 'subject_id')
            ->whereIn("tags.subject_id", $childSubjectIds)
            ->orderBy('tags.created_at', 'desc');

        if (count($childSubjectIds) > 1) {
            $query->select(DB::raw("tags.id,tags.name||'-('||subjects.name||')' as text"));
        } else {
            $query->select(DB::raw('tags.id,tags.name as text'));
        }

        if ($tagType) {
            $query->where("tags.type", $tagType);
        }


        if (!is_null($id)) {
            return $query->findOrFail($id);
        } else {

            if ($q) {
                $query->where('tags.name', '~*', "$q");
            }

            return $query->paginate($perPage, ['id', 'text']);
        }
    }
}
