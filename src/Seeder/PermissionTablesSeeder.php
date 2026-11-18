<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
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
     * 填充数据库初始数据。
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
//填充器占位符

    }
}
