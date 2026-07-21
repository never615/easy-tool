<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Illuminate\Support\Facades\DB;
use Mallto\Admin\Data\Traits\BaseModel;
use Mallto\Mall\Data\Shop;
use Mallto\User\Data\User;

/**
 * Tag：tag 对应的数据模型或持久化边界。
 * 它集中声明字段、关联和查询语义，供领域服务与管理界面复用。
 */
class Tag extends BaseModel
{
    protected $hidden = ['pivot', 'deleted_at'];

    //todo 主体可以设置的标签做成主体可配置的
    const TYPE = [
        'shop' => "店铺标签",
        'activity' => "活动标签",
        "user" => "用户自选标签",
//        "coupon"   => "卡券标签",
        'discount' => "会员优惠模块标签",
//        "common"   => "通用标签",
        'relic' => "文物",
        'locator' => '定位器',
        'area' => '区域',
        'fence' => '电子围栏',
        'area_alarm_rule' => '区域报警规则'
    ];


    /**
     * 处理「selectUserTags」相关的内部协作步骤。
     */
    public static function selectUserTags()
    {
        return static::dynamicData()->where("type", "user")->pluck("name", "id");
    }


    /**
     * @return mixed
     * @deprecated
     */
    public static function selectNotUserTags()
    {
        return static::dynamicData()->where("type", "common")->pluck("name", "id");
    }


    /**
     * 按类型查询标签
     *
     * @param $query
     * @param $type
     *
     * @return
     */
    public function scopeOfType($query, $type)
    {
        if (\Mallto\Admin\AdminUtils::isOwner()) {
            return static::dynamicData()->where(function ($query) use ($type) {
                $query->where("type", $type)->orWhere("type", "common");
            })->select(DB::raw("name||subject_id as name,id"))->pluck("name", "id");
        } else {
            return $query->dynamicData()->where(function ($query) use ($type) {
                $query->where("type", $type)->orWhere("type", "common");
            })->pluck("name", "id");
        }
    }


    /**
     * 处理「users」相关的内部协作步骤。
     */
    public function users()
    {
        return $this->morphedByMany(User::class, "taggable");
    }


    /**
     * 处理「shops」相关的内部协作步骤。
     */
    public function shops()
    {
        return $this->morphedByMany(Shop::class, "taggable");
    }

//    public function taggable()
//    {
//        return $this->morphTo();
//    }


}
