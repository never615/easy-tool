<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;
use Mallto\Tool\Seeder\TablesSeeder;

/**
 * UpdateCommand：update command 对应的 Artisan 命令。
 * 命令将运维入口收敛为可重复执行的流程。
 */
class UpdateCommand extends Command
{

    /**
     * 控制台命令名称。
     *
     * @var string
     */
    protected $name = 'tool:update';

    /**
     * 控制台命令说明。
     *
     * @var string
     */
    protected $description = 'Update the tool package';

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
        $this->call('db:seed', [ '--class' => TablesSeeder::class, '--force' => true ]);

    }

}
