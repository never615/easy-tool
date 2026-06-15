<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Seeder\Menu;

use Illuminate\Database\Seeder;
use Mallto\Admin\Data\Menu;
use Mallto\Admin\Seeder\MenuSeederMaker;

class ConfigMenuSeeder extends Seeder
{

    use MenuSeederMaker;

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $systemManagerMenu = Menu::where('uri', 'system_manager')->first();

        $configCenterOrder = $systemManagerMenu->order + 1;
        $monitorCenterOrder = $systemManagerMenu->order + 2;
        $logCenterOrder = $systemManagerMenu->order + 3;


        //--------------------- 配置中心

        $configCenterMenu = Menu::where('uri', 'config_center')->first();
        $configCenterMenu = $this->updateOrCreate(
            'config_center',
            0,
            $configCenterOrder,
            '配置中心',
            'fa-sliders'
        );

        $this->updateOrCreate(
            "new_configs.usage", $configCenterMenu->id, 1, "使用说明", "fa-book");

        $this->updateOrCreate(
            "configs.basic", $configCenterMenu->id, 10, "基础业务配置", "fa-bullseye");

        $this->updateOrCreate(
            "configs.sms", $configCenterMenu->id, 20, "短信与告警配置", "fa-envelope");

        $this->updateOrCreate(
            "configs.location_algorithm", $configCenterMenu->id, 30, "定位算法配置", "fa-map-marker");

        $this->updateOrCreate(
            "configs.location_maintenance", $configCenterMenu->id, 40, "定位维护配置", "fa-wrench");

        $this->updateOrCreate(
            "configs.beacon_area", $configCenterMenu->id, 50, "BeaconArea配置", "fa-map-signs");

        $this->updateOrCreate(
            "configs.location_debug", $configCenterMenu->id, 60, "定位日志配置", "fa-bug");

        $this->updateOrCreate(
            "new_configs.swoole_task_monitor", $configCenterMenu->id, 70, "Swoole Task配置", "fa-tasks");

        $this->updateOrCreate(
            "new_configs.publish_restart", $configCenterMenu->id, 80, "发布与重启", "fa-refresh");

        $traditionalConfigMenu = $this->updateOrCreate(
            "traditional_configs", $configCenterMenu->id, 90, "传统配置", "fa-archive");

        $this->updateOrCreate(
            "configs.index", $traditionalConfigMenu->id, 30, "全局配置", "fa-list");

        $this->updateOrCreate(
            "new_configs.index", $traditionalConfigMenu->id, 40, "运行期配置", "fa-sliders");


        //--------------------- 监控中心
        $monitorCenterMenu = $this->updateOrCreate(
            "monitor_center", 0, $monitorCenterOrder, "监控中心", "fa-desktop");

        $this->updateOrCreate(
            "swoole_task_monitor.index", $monitorCenterMenu->id, 10, "Swoole Task监控", "fa-tasks");

        $this->updateOrCreate(
            "queue_diagnostics.index", $monitorCenterMenu->id, 20, "队列诊断监控", "fa-line-chart");

        $this->updateOrCreate(
            "admin_monitor.horizon", $monitorCenterMenu->id, 30, "horizon 监控", "fa-dashboard");

        $this->updateOrCreate(
            "admin_monitor.swoole_stats", $monitorCenterMenu->id, 40, "swoole 监控", "fa-area-chart");

        //--------------------- 日志中心

        $logCenterMenu = $this->updateOrCreate(
            "log_center", 0, $logCenterOrder, "日志中心", "fa-list-alt");

        $this->updateOrCreate(
            "third_logs.index", $logCenterMenu->id, 40, "第三方接口通讯日志", "fa-history");

        $this->updateOrCreate(
            "system_logs.index", $logCenterMenu->id, 50, "项目日志", "fa-file-text-o");

        $this->updateOrCreate(
            "owner_logs.index", $logCenterMenu->id, 30, "自己上报的日志", "fa-database");

    }
}
