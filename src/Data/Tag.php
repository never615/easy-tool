<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Encore\Admin\Facades\Admin;
use Illuminate\Support\Facades\DB;
use Mallto\Admin\Data\Traits\BaseModel;
use Mallto\Mall\Data\Shop;
use Mallto\User\Data\User;


class Tag extends BaseModel
{

    const TYPE = [
        'shop'     => "店铺标签",
        'activity' => "活动标签",
        "user"     => "用户自选标签",
//        "coupon"   => "卡券标签",
        'discount' => "会员优惠模块标签",
//        "common"   => "通用标签",
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
        if (Admin::user()->isOwner()) {
            return static::dynamicData()
                ->where(function ($query) use ($type) {
                    $query->where("type", $type)
                        ->orWhere("type", "common");
                })
                ->select(DB::raw("name||subject_id as name,id"))->pluck("name", "id");
        } else {
            return $query->dynamicData()
                ->where(function ($query) use ($type) {
                    $query->where("type", $type)
                        ->orWhere("type", "common");
                })
                ->pluck("name", "id");
        }
    }


    //todo mall库引用处理
    public function shops()
    {
        return $this->morphedByMany(Shop::class, "taggable");
    }

    public function users()
    {
        return $this->morphedByMany(User::class, "taggable");
    }


}
