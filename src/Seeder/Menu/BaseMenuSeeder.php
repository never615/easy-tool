<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Seeder\Menu;

use Illuminate\Database\Seeder;
use Mallto\Admin\Data\Menu;
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
        $order = 2;

        $adminManagerMenu = Menu::where("uri", "admin_manager")->first();

        $order = $adminManagerMenu->order;

        $this->updateOrCreate(
            "wechat_template_ids.index", $adminManagerMenu->id, 6, "微信模板消息管理", "fa-wechat");

        $this->updateOrCreate(
            "configs.index", $adminManagerMenu->id, $order++, "全局配置", "fa-bullseye");

        $systemManagerMenu = $this->updateOrCreate(
            "system_manager", 0, $order++, "系统管理", "fa-windows");

        // 开放平台管理
        $thirdApiManagerMenu = $this->updateOrCreate('third_api_manager', $systemManagerMenu->id, $order++,
            '开放平台管理',
            'fa-cloud');

        // 开放平台用户管理
        $this->updateOrCreate('app_secrets.index', $thirdApiManagerMenu->id, $order++, '开发者管理',
            'fa-users');

        // 开放平台用户角色管理
        $this->updateOrCreate('app_secrets_role.index', $thirdApiManagerMenu->id, $order++, '开发者角色管理',
            'fa-user');

        // 开放平台接口权限管理
        $this->updateOrCreate('app_secrets_permission.index', $thirdApiManagerMenu->id, $order++,
            '开发者接口权限管理',
            'fa-sitemap');

        $this->updateOrCreate(
            "third_logs.index", $systemManagerMenu->id, $order++, "第三方日志", "fa-history");

    }
}
