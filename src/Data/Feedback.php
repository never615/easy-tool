<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Data;

use Illuminate\Database\Eloquent\Model;
use Mallto\Admin\Data\Traits\DynamicData;

/**
 * 意见反馈
 * Log 类
 *
 */
class Feedback extends Model
{

    use DynamicData;

    protected $table = "feedbacks";

    protected $guarded = [
    ];
}
