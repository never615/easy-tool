<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Seeder;

use Illuminate\Database\Seeder;

/**
 * TablesSeeder：初始化 tables seeder 所需的基础数据。
 * 种子过程保持幂等，以便在安装、升级或测试环境中重复执行。
 */
class TablesSeeder extends Seeder
{

    /**
     * Run the database seeds.
     *
     * @param MenuTablesSeeder       $menuTablesSeeder
     * @param PermissionTablesSeeder $permissionTablesSeeder
     *
     * @return void
     */
    public function run(MenuTablesSeeder $menuTablesSeeder, PermissionTablesSeeder $permissionTablesSeeder)
    {
        $menuTablesSeeder->run();
        $permissionTablesSeeder->run();
        $this->call(ConfigTablesSeeder::class);
        $this->call(NewConfigDocumentSeeder::class);
    }
}
