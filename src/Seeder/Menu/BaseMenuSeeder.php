<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Seeder\Menu;

use Illuminate\Database\Seeder;
use Mallto\Admin\Seeder\MenuSeederMaker;

class BaseMenuSeeder extends Seeder
{
    use MenuSeederMaker;

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $order = 100;

        $adminManagerMenu = $this->updateOrCreate(
            "admin_manager", 0, $order++, "管理", "fa-tasks");

        $order = $adminManagerMenu->order;


        $this->updateOrCreate(
            "wechat_template_ids.index", $adminManagerMenu->id, $order++, "模板消息管理", "fa-wechat");



        $order = 101;

        $systemManagerMenu = $this->updateOrCreate(
            "system_manager", 0, $order++, "系统管理", "fa-windows");

        $this->updateOrCreate(
            "app_secrets.index", $systemManagerMenu->id, $order++, "app_secrets", "fa-user-secret");

        $this->updateOrCreate(
            "configs.index", $systemManagerMenu->id, $order++, "系统通用配置项", "fa-bullseye");

        $this->updateOrCreate(
            "third_logs.index", $systemManagerMenu->id, $order++, "第三方日志", "fa-history");

    }
}
