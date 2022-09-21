<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * 清理缓存
 *
 * @package Mallto\Tool\Commands
 */
class ClearCacheCommand extends Command
{

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'tool:clear_cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '清理缓存';

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

        //查询redis 是否有清理任务
        $isClearCache = Cache::get('clear_cache_task');
        if ($isClearCache === 1) {
            \Log::warning('clear cache by schedule');
            //正常情况下只清理缓存库
            Artisan::call('cache:clear');
            Artisan::call('cache:clear local_redis');
        }

        $this->info("finish");
    }

}
