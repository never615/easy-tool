<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Encore\Admin\Auth\Database\Traits\DynamicData;
use Illuminate\Database\Eloquent\Model;

/**
 * 意见反馈
 * Class Log
 *
 * @package Mallto\Mall\Module\Log
 */
class Feedback extends Model
{
    use DynamicData;

    protected $table = "feedbacks";

    protected $guarded = [
    ];
}
