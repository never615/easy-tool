<?php
/*
 * Copyright (c) 2022. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\App;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;

/**
 * User: never615 <never615.com>
 * Date: 2022/9/7
 * Time: 2:57 PM
 */
class ClearCacheUsecase
{

    /**
     * 清理缓存
     *
     * @param bool   $cache  是否只清理 redis cache 库
     * @param string $prefix redis key 前缀
     *
     * @return void
     */
    public function clearCache($cache = true, $prefix = '')
    {
        if ( ! $cache) {
            \Log::warning('clear all');

            // 需要在前面连接上应用的缓存前缀
            $keys = app('redis')->keys($prefix . '*');

            if ( ! empty($keys)) {
                app('redis')->del($keys);
            }
        }

        $cachePrefix = config('app.unique') . '_' . config('app.env')
            . ':' . $prefix . '*';

        //\Log::info($cachePrefix);

        $keys = Redis::connection('cache')
            ->keys($cachePrefix);

        if ( ! empty($keys)) {
            Redis::connection('cache')->del($keys);
        }

        try {
            //本地数据库
            $keys = Redis::connection('local')
                ->keys($cachePrefix);
            if ( ! empty($keys)) {
                Redis::connection('local')->del($keys);
            }
        } catch (\Exception $exception) {

        }

        Artisan::call('cache:clear');
    }
}
