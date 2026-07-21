<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Mallto\Admin\Data\Traits\BaseModel;

/**
 * PagePvManager：page pv manager 对应的数据模型或持久化边界。
 * 它集中声明字段、关联和查询语义，供领域服务与管理界面复用。
 */
class PagePvManager extends BaseModel
{

    protected $table = 'page_pv_manager';

    protected $casts = [
        "ad_types" => "array",
    ];

    public $selectName = "name";

    public $selectId = "path";

}
