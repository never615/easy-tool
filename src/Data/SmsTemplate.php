<?php

namespace Mallto\Tool\Data;

use Encore\Admin\Facades\Admin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mallto\Admin\Data\Traits\BaseModel;

class SmsTemplate extends BaseModel
{
    protected $table = 'sms_templates';


//    public function scopeSelectSourceDatas()
//    {
//        if (\Mallto\Admin\AdminUtils::isOwner() && Schema::hasColumn($this->getTable(), 'subject_id')) {
//            return static::dynamicData()
//                ->select(DB::raw("name||'-'||subject_id as name,code"))->pluck("name", "code");
//        } else {
//            return static::dynamicData()->pluck("name", "code");
//        }
//    }
}
