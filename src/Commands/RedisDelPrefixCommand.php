<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;
use Mallto\Tool\Domain\App\ClearCacheUsecase;

/**
 * RedisDelPrefixCommand 类
 *
 * @package Mallto\Tool\Commands
 */
class RedisDelPrefixCommand extends Command
{

    /**
     * 控制台命令名称。
     *
     * @var string
     */
    protected $signature = 'tool:redis_del_prefix {--prefix=} {--cache=1}';

    /**
     * 控制台命令说明。
     *
     * @var string
     */
    protected $description = '删除指定开头的key';

    /**
     * 安装目录。
     *
     * @var string
     */
    protected $directory = '';


    /**
     * 执行控制台命令。
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
