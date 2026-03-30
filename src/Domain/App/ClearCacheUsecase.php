<?php
/*
 * Copyright (c) 2022. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Domain\App;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Predis\Connection\ConnectionException;

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
     * @param bool $cache 是否只清理 redis cache 库
     * @param string $prefix redis key 前缀
     *
     * @return void
     */
    public function clearCache($cache = true, $prefix = '')
    {
        Log::warning('clear cache', [$cache, $prefix]);

        //正常情况下只清理缓存库
        Artisan::call('cache:clear');

        Artisan::call('swoole-table:flush');

        try {
            Artisan::call('cache:clear local_redis');
        } catch (ConnectionException $connectionException) {
            //本地 redis 库在部署的时候会清理一次缓存,但是还没启动会报错
        }

        if (config('cache.default') === 'redis') {
            //删除直接使用redis保存的定位结果
            $keys = app('redis')->keys('l_res_c_*');

//            Log::debug($keys);

            app('redis')->del($keys);

//            if (!empty($keys)) {
//                foreach ($keys as $key) {
//                    app('redis')->del($key);
//                }
//            }

            if ($prefix) {
                // 需要在前面连接上应用的缓存前缀
                $prefix = config('cache.prefix') . ':' . $prefix . '*';
                try {
                    $keys = Redis::connection('local_cache')
                        ->keys($prefix);

                    if (!empty($keys)) {
                        Redis::connection('local_cache')->del($keys);
                    }
                } catch (ConnectionException $connectionException) {
                    //本地 redis 库在部署的时候会清理一次缓存,但是还没启动会报错
                }

                $keys = Redis::connection('cache')
                    ->keys($prefix);

                if (!empty($keys)) {
                    Redis::del($keys);
                }
            } else {
                if (!$cache) {
                    Log::warning('clear all');
                    //清理默认 redis,存储的 session horizon
                    app('redis')->flushall();

                    //keys 操作大量数据的时候会卡死一下
                }
            }
        }

        // 缓存清理后，重新预热 locator MAC 集合（在所有 Redis 清理操作完成后，从 DB 全量重建）。
        // 使用 try-catch：easy-location 包未安装时命令不存在，不影响其他业务。
        try {
            Artisan::call('location:mac-cache-warm');
        } catch (\Throwable $e) {
            // easy-location 未安装或预热失败，不影响主流程
        }
    }
}
