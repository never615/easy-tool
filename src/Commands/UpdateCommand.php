<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
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
     * The console command name.
     *
     * @var string
     */
    protected $name = 'tool:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the tool package';

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
        $this->call('db:seed', [ '--class' => TablesSeeder::class, '--force' => true ]);

    }

}
