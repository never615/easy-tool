<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Mallto\Admin\Data\Traits\DynamicData;
use Illuminate\Database\Eloquent\Model;

/**
 * 系统日志
 * Class Log
 *
 * @package Mallto\Mall\Module\Log
 */
class Log extends Model
{
    use DynamicData;

    protected $guarded = [
    ];
}
