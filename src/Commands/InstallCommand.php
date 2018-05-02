<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;
use Mallto\Tool\Seeder\ToolTablesSeeder;

class InstallCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'tool:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install the tool package';

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
        $this->publishDatabase();

    }

    /**
     * Create tables and seed it.
     *
     * @return void
     */
    public function publishDatabase()
    {
        $this->call('migrate', ['--path' => str_replace(base_path(), '', __DIR__).'/../../migrations/']);

        $this->call('db:seed', ['--class' => ToolTablesSeeder::class]);
    }
}
