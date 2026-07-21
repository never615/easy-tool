<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Mallto\Admin\Data\Traits\BaseModel;

/**
 * AdImage：ad image 对应的数据模型或持久化边界。
 * 它集中声明字段、关联和查询语义，供领域服务与管理界面复用。
 */
class AdImage extends BaseModel
{

    /**
     * 处理「ad」相关的内部协作步骤。
     */
    public function ad()
    {
        return $this->belongsTo(Ad::class);
    }

}
