<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 清理failed_jobs日志
 *
 * @package Mallto\Tool\Commands
 */
class DeleteFailedJobsCommand extends Command
{

    /**
     * 控制台命令名称。
     *
     * @var string
     */
    protected $signature = 'tool:delete_failed_jobs_log';

    /**
     * 控制台命令说明。
     *
     * @var string
     */
    protected $description = '清理日志';

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
        $oneMonthAgo = now()->subMonth();
        // 使用查询构建器来删除一个月前的失败任务
        DB::table('failed_jobs')->where('failed_at', '<', $oneMonthAgo)->delete();
    }

}
