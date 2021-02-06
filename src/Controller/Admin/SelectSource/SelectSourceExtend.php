<?php
/**
 * Copyright (c) 2021. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin\SelectSource;

use Mallto\Admin\Controllers\Base\SelectSourceExtendInterface;
use Mallto\Tool\Data\Ad;
use Mallto\Tool\Data\PagePvManager;

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

                $adTypes = array_unique(array_merge($adTypes, [ "float_image" ]));

                $adTypes = array_only(Ad::AD_TYPE, $adTypes);

                $temps = [];
                foreach ($adTypes as $adTypesKey => $value) {
                    $temps[] = [
                        "id"   => $adTypesKey,
                        "text" => $value,
                    ];
                }

                return $temps;
            } else {
                return [];
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
