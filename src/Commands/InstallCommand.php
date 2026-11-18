<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;
use Mallto\Tool\Seeder\TablesSeeder;

/**
 * InstallCommand：install command 对应的 Artisan 命令。
 * 命令将运维入口收敛为可重复执行的流程。
 */
class InstallCommand extends Command
{

    /**
     * 控制台命令名称。
     *
     * @var string
     */
    protected $name = 'tool:install';

    /**
     * 控制台命令说明。
     *
     * @var string
     */
    protected $description = 'Install the tool package';

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
        $this->publishDatabase();

    }


    /**
     * 创建数据表并填充初始数据。
     *
     * @return void
     */
    public function publishDatabase()
    {
        $this->call('migrate',
            [ '--path' => str_replace(base_path(), '', __DIR__) . '/../../migrations/', '--force' => true ]);

        $this->call('db:seed', [ '--class' => TablesSeeder::class, '--force' => true ]);
    }
}
