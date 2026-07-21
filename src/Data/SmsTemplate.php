<?php

namespace Mallto\Tool\Data;

use Mallto\Admin\Data\Traits\BaseModel;

/**
 * SmsTemplate：sms template 对应的数据模型或持久化边界。
 * 它集中声明字段、关联和查询语义，供领域服务与管理界面复用。
 */
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
