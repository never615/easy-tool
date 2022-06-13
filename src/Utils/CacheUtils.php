<?php
/*
 * Copyright (c) 2022. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Utils;

use Illuminate\Support\Facades\Redis;

/**
 * User: never615 <never615.com>
 * Date: 2022/3/15
 * Time: 5:04 下午
 */
class CacheUtils
{

    public static function clear($prefix)
    {
        $cachePrefix = config('app.unique') . '_' . config('app.env')
            . ':' . $prefix . '*';

        $keys = Redis::connection('cache')
            ->keys($cachePrefix);

        if ($keys) {
            Redis::connection('cache')->del($keys);
        }
    }
}
