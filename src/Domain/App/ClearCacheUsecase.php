<?php
/*
 * Copyright (c) 2022. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\App;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

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
        if ($prefix) {
            Cache::
        } else {
            if ( ! $cache) {
                \Log::warning('clear all');
                //清理默认 redis,存储的 session horizon
                app('redis')->flush();

                //keys 操作大量数据的时候会卡死一下
                //$keys = app('redis')->keys('*');
                //
                //if ( ! empty($keys)) {
                //    app('redis')->del($keys);
                //}
            }

            //正常情况下只清理缓存库
            Artisan::call('cache:clear');
            Artisan::call('cache:clear remote_redis');
        }
    }
}
