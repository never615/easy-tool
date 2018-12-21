<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Mallto\Admin\Data\Traits\BaseModel;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApiPvManager extends BaseModel
{
    protected $table = 'api_pv_managers';
}
