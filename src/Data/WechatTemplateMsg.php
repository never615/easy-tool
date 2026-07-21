<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Mallto\Admin\Data\Traits\BaseModel;
use Mallto\Admin\Data\Traits\DynamicData;

/**
 * WechatTemplateMsg：wechat template msg 对应的数据模型或持久化边界。
 * 它集中声明字段、关联和查询语义，供领域服务与管理界面复用。
 */
class WechatTemplateMsg extends BaseModel
{

    use DynamicData;

    protected $guarded = [

    ];

}
