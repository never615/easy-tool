<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Illuminate\Database\Eloquent\Model;
use Mallto\Admin\Data\Traits\DynamicData;

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
