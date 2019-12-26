<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Illuminate\Database\Eloquent\Model;
use Mallto\Admin\Data\Traits\DynamicData;

/**
 * 系统日志
 * Class Log
 *
 * @package Mallto\Tool\Data
 */
class Log extends Model
{

    use DynamicData;

    protected $guarded = [
    ];
}
