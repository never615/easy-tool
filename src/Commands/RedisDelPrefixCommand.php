<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;
use Mallto\Tool\Domain\App\ClearCacheUsecase;

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

        $clearCacheUsecase = app(ClearCacheUsecase::class);
        $clearCacheUsecase->clearCache($cache, $prefix);

        $this->info("finish");
    }

}
