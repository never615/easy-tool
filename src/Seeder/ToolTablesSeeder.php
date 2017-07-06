<?php

namespace Mallto\Tool\Seeder;

use Illuminate\Database\Seeder;

class ToolTablesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->call(MenuTablesSeeder::class);
        $this->call(PermissionTablesSeeder::class);
    }
}