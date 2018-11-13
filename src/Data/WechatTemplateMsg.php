<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;


use Mallto\Admin\Data\Traits\BaseModel;
use Mallto\Admin\Data\Traits\DynamicData;


class WechatTemplateMsg extends BaseModel
{
    use DynamicData;


    //微信模板
    const WECHAT_TEMPLATE = [
        "TM00504"         => "自助积分失败通知",
        "OPENTM202764141" => "会员积分变动通知",
        "OPENTM407889520" => "卡券核销通知",
        "OPENTM401208280" => "卡券过期/到期提醒",
    ];

    protected $guarded = [

    ];


}
