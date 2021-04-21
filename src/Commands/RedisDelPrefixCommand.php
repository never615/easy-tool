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
    protected $signature = 'tool:redis_del_prefix {--prefix=}';

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

        // 需要在前面连接上应用的缓存前缀
        $keys = app('redis')->keys($prefix . '*');

        app('redis')->del($keys);

        $cachePrefix = config('app.unique') . '_' . config('app.env')
            . ':' . $prefix . '*';

        $keys = Redis::connection('cache')
            ->keys($cachePrefix);

        Redis::connection('cache')->del($keys);

        if (config('app.env') === 'local') {
            Artisan::call('cache:clear');
        }

        $this->info("finish");

    }

}
