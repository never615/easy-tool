<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Mallto\Admin\Data\Traits\BaseModel;
use Mallto\Mall\Data\Shop;
use Mallto\User\Data\User;


class Tag extends BaseModel
{
    const TYPE = [
        "common" => "通用标签",
        "user"   => "用户自选标签",
        "coupon" => "卡券标签",
    ];


    public static function selectUserTags()
    {
        return static::dynamicData()
            ->where("type", "user")
            ->pluck("name", "id");
    }

    /**
     * @deprecated
     * @return mixed
     */
    public static function selectNotUserTags()
    {
        return static::dynamicData()
            ->where("type", "common")
            ->pluck("name", "id");
    }


    /**
     * 按类型查询标签
     *
     * @param $query
     * @param $type
     * @return
     */
    public function scopeOfType($query, $type)
    {
        return $query->dynamicData()
            ->where("type", $type)
            ->pluck("name", "id");
    }


    public function shops()
    {
        return $this->morphedByMany(Shop::class, "taggable");
    }

    public function users()
    {
        return $this->morphedByMany(User::class, "taggable");
    }


}
