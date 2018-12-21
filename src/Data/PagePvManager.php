<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Encore\Admin\Facades\Admin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mallto\Admin\Data\Traits\BaseModel;

class PagePvManager extends BaseModel
{
    protected $table = 'page_pv_manager';

    protected $casts = [
        "ad_types" => "array",
    ];


    public function scopeSelectSourceDatas()
    {
        if (Admin::user()->isOwner() && Schema::hasColumn($this->getTable(), 'subject_id')) {
            return static::dynamicData()
                ->select(DB::raw("name||'-'||subject_id as name,path"))->pluck("name", "path");
        } else {
            return static::dynamicData()->pluck("name", "path");
        }
    }

    /**
     * 与scopeSelectSourceDatas()相比,返回的是一个查询对象,不是查询结果
     *
     * @return mixed
     */
    public function scopeSelectSourceDatas2()
    {
        if (Admin::user()->isOwner()) {
            if (Schema::hasColumn($this->getTable(), 'subject_id')) {
                return static::dynamicData()
                    ->select(DB::raw("name||'-'||subject_id as name,path"));
            } else {
                return static::dynamicData()
                    ->select(DB::raw("name as name,path"));
            }
        } else {
            return static::dynamicData();
        }
    }

}
