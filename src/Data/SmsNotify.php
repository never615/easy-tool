<?php

namespace Mallto\Tool\Data;

use Mallto\Admin\Data\Traits\BaseModel;

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


    public function template()
    {
        return $this->belongsTo(SmsTemplate::class, "sms_template_id");
    }

}
