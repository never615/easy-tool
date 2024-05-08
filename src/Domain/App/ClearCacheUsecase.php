<?php
/*
 * Copyright (c) 2022. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\App;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Predis\Connection\ConnectionException;
use Illuminate\Support\Facades\Log;

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
        Log::warning('clear cache', [ $cache, $prefix ]);

        //正常情况下只清理缓存库
        Artisan::call('cache:clear');

        try {
            Artisan::call('cache:clear local_redis');
        } catch (ConnectionException $connectionException) {
            //本地 redis 库在部署的时候会清理一次缓存,但是还没启动会报错
        }

        if (config('cache.default') === 'redis') {
            if ($prefix) {
                // 需要在前面连接上应用的缓存前缀
                $prefix = config('cache.prefix') . ':' . $prefix . '*';
                try {
                    $keys = Redis::connection('local_cache')
                        ->keys($prefix);

                    if ( ! empty($keys)) {
                        Redis::connection('local_cache')->del($keys);
                    }
                } catch (ConnectionException $connectionException) {
                    //本地 redis 库在部署的时候会清理一次缓存,但是还没启动会报错

                }

                $keys = Redis::connection('cache')
                    ->keys($prefix);

                if ( ! empty($keys)) {
                    Redis::del($keys);
                }
            } else {
                if ( ! $cache) {
                    Log::warning('clear all');
                    //清理默认 redis,存储的 session horizon
                    app('redis')->flushdb();

                    //keys 操作大量数据的时候会卡死一下
                    //$keys = app('redis')->keys('*');
                    //
                    //if ( ! empty($keys)) {
                    //    app('redis')->del($keys);
                    //}
                }
            }
        }

        //添加清理任务到缓存中,用于多服务器清理缓存,每5分钟所有服务器检查一次是否需要有清理缓存的任务
        Cache::put('clear_cache_task', "clear_cache_task", Carbon::now()->addMinutes(7));
    }
}
