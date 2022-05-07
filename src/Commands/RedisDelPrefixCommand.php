<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;

/**
 * 重设表的自增序列
 * Class ResetTableIdSeqCommand
 *
 * @package Mallto\Tool\Commands
 */
class RedisDelPrefixCommand extends Command
{

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'tool:redis_del_prefix {--prefix=} {--cache}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '删除指定开头的key';

    /**
     * Install directory.
     *
     * @var string
     */
    protected $directory = '';


    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->info("start");

        // 前缀
        $prefix = $this->option('prefix');
        //是否只清理缓存
        $cache = $this->option('cache');

        if ($cache) {
            $this->info("clear cache");
        } else {
            $this->info("clear all");
        }

        if ( ! $cache) {
            // 需要在前面连接上应用的缓存前缀
            $keys = app('redis')->keys($prefix . '*');
            app('redis')->del($keys);
        }

        //清理缓存
        $this->cacheClear($prefix);

        $this->info("finish");
    }


    private function cacheClear($prefix)
    {
        $cachePrefix = config('app.unique') . '_' . config('app.env')
            . ':' . $prefix . '*';

        //\Log::info($cachePrefix);

        $keys = Redis::connection('cache')
            ->keys($cachePrefix);

        Redis::connection('cache')->del($keys);

        try {
            //本地数据库
            $keys = Redis::connection('local')->keys($prefix . '*');
            Redis::connection('local')->del($keys);

            $keys = Redis::connection('local')
                ->keys($cachePrefix);
            Redis::connection('local')->del($keys);
        } catch (\Exception $exception) {

        }

        //if (config('app.env') === 'local') {
        Artisan::call('cache:clear');
        //}
    }

}
