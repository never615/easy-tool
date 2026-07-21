<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Mallto\Admin\Data\Traits\BaseModel;

/**
 * ApiPvManager：api pv manager 对应的数据模型或持久化边界。
 * 它集中声明字段、关联和查询语义，供领域服务与管理界面复用。
 */
class ApiPvManager extends BaseModel
{

    protected $table = 'api_pv_managers';
}
