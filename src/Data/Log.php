<?php

namespace Mallto\Tool\Data;

use Encore\Admin\Auth\Database\Traits\DynamicData;
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
