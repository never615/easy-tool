<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Mallto\Admin\Data\Traits\DynamicData;
use Illuminate\Database\Eloquent\Model;


/**
 * 意见反馈
 * Class Log
 *
 */
class Feedback extends Model
{
    use DynamicData;

    protected $table = "feedbacks";

    protected $guarded = [
    ];
}
