<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Encore\Admin\Auth\Database\Traits\BaseModel;
use Mallto\Mall\Data\Shop;
use Mallto\User\Data\User;


class Tag extends BaseModel
{
    const TYPE = [
        "common" => "通用标签",
        "user"   => "用户自选标签",
    ];


    public static function selectUserTags()
    {
        return static::dynamicData()
            ->where("type", "user")
            ->pluck("name", "id");
    }

    public static function selectNotUserTags()
    {
        return static::dynamicData()
            ->where("type", "!=", "user")
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
