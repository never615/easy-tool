<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Seeder\Menu;

use Illuminate\Database\Seeder;

class WechatTemplateSeeder extends Seeder
{


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


        $this->updateOrCreate(
            "app_secrets.index", $adminManagerMenu->id, $order++, "app_secrets", "fa-user-secret");

        $this->updateOrCreate(
            "third_logs.index", $adminManagerMenu->id, $order++, "第三方日志", "fa-history");

    }
}
