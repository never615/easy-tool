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
            "queue_diagnostics.index", $adminManagerMenu->id, $order++, "队列诊断监控", "fa-line-chart");

        $systemManagerMenu = $this->updateOrCreate(
            "system_manager", 0, $order++, "系统管理", "fa-windows");

        $configCenterMenu = Menu::where('uri', 'config_center')->first();
        $configCenterMenu = $this->updateOrCreate(
            'config_center',
            0,
            $configCenterMenu ? null : $order++,
            '配置中心',
            'fa-sliders'
        );

        $this->updateOrCreate(
            "configs.basic", $configCenterMenu->id, 10, "基础业务配置", "fa-bullseye");

        $this->updateOrCreate(
            "configs.sms", $configCenterMenu->id, 20, "短信与告警配置", "fa-envelope");

        $this->updateOrCreate(
            "configs.location_algorithm", $configCenterMenu->id, 30, "定位算法配置", "fa-map-marker");

        $this->updateOrCreate(
            "configs.location_maintenance", $configCenterMenu->id, 40, "定位维护配置", "fa-wrench");

        $this->updateOrCreate(
            "configs.location_debug", $configCenterMenu->id, 50, "定位日志配置", "fa-bug");

        $this->updateOrCreate(
            "new_configs.swoole_task_monitor", $configCenterMenu->id, 60, "Swoole Task配置", "fa-tasks");

        $this->updateOrCreate(
            "configs.index", $configCenterMenu->id, 80, "全局配置列表", "fa-list");

        $this->updateOrCreate(
            "new_configs.index", $configCenterMenu->id, 90, "运行期配置", "fa-sliders");

        $this->updateOrCreate(
            "swoole_task_monitor.index", $systemManagerMenu->id, $order++, "Swoole Task监控", "fa-tasks");

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
