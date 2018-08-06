<?php

namespace Mallto\Tool\Data;

use Mallto\Admin\Data\Traits\BaseModel;

class SmsNotify extends BaseModel
{

    const STATUS = [
        "not_start"  => "未开始",
        "processing" => "进行中",
        "finish"     => "已完成",
        'failure'    => "失败",
    ];
    protected $table = 'sms_notifies';


}
