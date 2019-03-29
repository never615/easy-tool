<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Encore\Admin\Facades\Admin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mallto\Admin\Data\Traits\BaseModel;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2018/11/21
 * Time: 4:07 PM
 */
class PagePv extends BaseModel
{

    protected $table = "page_pv";


    public function scopeSelectSourceDatas()
    {
        if (\Mallto\Admin\AdminUtils::isOwner() && Schema::hasColumn($this->getTable(), 'subject_id')) {
            return static::dynamicData()
                ->select(DB::raw("path||'-'||subject_id as path,path"))->pluck("path", "path");
        } else {
            return static::dynamicData()->pluck("path", "path");
        }
    }



    public function scopeSelectSourceDatas2()
    {
        if (\Mallto\Admin\AdminUtils::isOwner()) {
            if (Schema::hasColumn($this->getTable(), 'subject_id')) {
                return static::dynamicData()
                    ->select(DB::raw("path||'-'||subject_id as path,path"));
            } else {
                return static::dynamicData()
                    ->select(DB::raw("path,path"));
            }
        } else {
            return static::dynamicData();
        }
    }


}