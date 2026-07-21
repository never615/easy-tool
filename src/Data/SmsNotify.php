<?php

namespace Mallto\Tool\Data;

use Mallto\Admin\Data\Traits\BaseModel;

/**
 * SmsNotify：sms notify 对应的数据模型或持久化边界。
 * 它集中声明字段、关联和查询语义，供领域服务与管理界面复用。
 */
class SmsNotify extends BaseModel
{

    const STATUS = [
        "not_start"  => "未开始",
        "processing" => "进行中",
        "handling"   => "进行中",
        "success"    => "已完成",
        'failure'    => "失败,请重新发送",
    ];

    protected $table = 'sms_notifies';


    /**
     * 处理「template」相关的内部协作步骤。
     */
    public function template()
    {
        return $this->belongsTo(SmsTemplate::class, "sms_template_id");
    }

}
