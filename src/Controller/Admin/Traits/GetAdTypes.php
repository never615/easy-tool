<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin\Traits;

use Mallto\Tool\Data\Ad;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2018/12/21
 * Time: 5:51 PM
 */
trait GetAdTypes
{

    private function getAdTypes($pagePvManager, $isOption = false)
    {
        $adTypes = $pagePvManager->ad_types;

        $adTypes = array_unique(array_merge($adTypes, [ "float_image" ]));

        $adTypes = array_only(Ad::AD_TYPE, $adTypes);

        if ($isOption) {
            $temps = $adTypes;
        } else {
            $temps = [];
            foreach ($adTypes as $key => $value) {
                $temps[] = [
                    "id"   => $key,
                    "text" => $value,
                ];
            };
        }

        return $temps;
    }
}
