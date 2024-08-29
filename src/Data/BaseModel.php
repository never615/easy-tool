<?php
/*
 * Copyright (c) 2024. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */
namespace Mallto\Tool\Data;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * User: never615 <never615.com>
 * Date: 2024/8/29
 * Time: 16:41
 */
abstract class BaseModel extends Model
{

    protected $hidden = ['deleted_at'];

    protected $guarded = [];

//    /**
//     * 为数组 / JSON 序列化准备日期。
//     *
//     * @param \DateTimeInterface $date
//     * @return string
//     */
//    protected function serializeDate(\DateTimeInterface $date)
//    {
//        return $date->format($this->dateFormat ?: 'Y-m-d H:i:s');
//    }

    protected function serializeDate(\DateTimeInterface $date)
    {
        if (version_compare(app()->version(), '7.0.0') < 0) {
            return parent::serializeDate($date);
        }

        return $date->format(Carbon::DEFAULT_TO_STRING_FORMAT);
    }

}