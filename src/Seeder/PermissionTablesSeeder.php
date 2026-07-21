<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Seeder;

use Illuminate\Database\Seeder;
use Mallto\Tool\Seeder\Permission\BasePermissionsSeeder;
use Mallto\Tool\Seeder\Permission\PagePermissionsSeeder;

/**
 * PermissionTablesSeeder：初始化 permission tables seeder 所需的基础数据。
 * 种子过程保持幂等，以便在安装、升级或测试环境中重复执行。
 */
class PermissionTablesSeeder extends Seeder
{

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->call(\Mallto\Tool\Seeder\Permission\SmsNotifyPermissionSeeder::class);
        $this->call(PagePermissionsSeeder::class);
        $this->call(BasePermissionsSeeder::class);

        $this->call(\Mallto\Tool\Seeder\Permission\SmsTemplatePermissionSeeder::class);
        $this->call(\Mallto\Tool\Seeder\Permission\SmsCodePermissionSeeder::class);
        $this->call(\Mallto\Tool\Seeder\Permission\FeedbackPermissionsSeeder::class);
        $this->call(\Mallto\Tool\Seeder\Permission\AlertRulePermissionSeeder::class);
//DummySeeder

    }
}
