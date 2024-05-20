<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
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
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'tool:delete_failed_jobs_log';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '清理日志';

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
        $oneMonthAgo = now()->subMonth();
        // 使用查询构建器来删除一个月前的失败任务
        DB::table('failed_jobs')->where('failed_at', '<', $oneMonthAgo)->delete();
    }

}
