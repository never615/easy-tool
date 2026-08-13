<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Seeder\Menu;

use Illuminate\Database\Seeder;
use Mallto\Admin\Data\Menu;
use Mallto\Admin\Seeder\MenuSeederMaker;

/**
 * ConfigMenuSeeder：初始化 config menu seeder 所需的基础数据。
 * 种子过程保持幂等，以便在安装、升级或测试环境中重复执行。
 */
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
            "new_configs.swoole_task_monitor", $configCenterMenu->id, 70, "Swoole Task配置", "fa-tasks");

        $this->updateOrCreate(
            "new_configs.runtime_env_overrides.index", $configCenterMenu->id, 75, "运行期 Env 覆盖", "fa-terminal");

        $this->updateOrCreate(
            "new_configs.publish_restart", $configCenterMenu->id, 80, "发布启动配置", "fa-refresh");

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

        /*
         * 先把同一“监控中心”下的旧 Swoole 菜单原地迁移，再执行统一的 upsert。
         *
         * 正常升级时，新 URI 尚不存在，原地更新会保留菜单主键及其既有角色关联；限定
         * parent_id 可避免误改其他模块碰巧复用同 URI 的历史数据。若部分发布或手工操作已
         * 同时留下旧、新两行，则不能再把旧行 update 成重复的新 URI：此时保留现有新行并
         * 删除旧行。最后统一 upsert，使后续重复执行 Seeder 始终只维护一个 AppStats 菜单。
         */
        $appStatsMenuExists = Menu::query()
            ->where('uri', 'admin_monitor.app_stats')
            ->where('parent_id', $monitorCenterMenu->id)
            ->exists();

        if (! $appStatsMenuExists) {
            Menu::query()
                ->where('uri', 'admin_monitor.swoole_stats')
                ->where('parent_id', $monitorCenterMenu->id)
                ->update([
                    'uri' => 'admin_monitor.app_stats',
                    'title' => '应用运行监控',
                    'icon' => 'fa-area-chart',
                    'order' => 40,
                ]);
        }

        // 清除异常部分发布留下的旧行；正常原地迁移后此查询为空，重复执行同样无副作用。
        Menu::query()
            ->where('uri', 'admin_monitor.swoole_stats')
            ->where('parent_id', $monitorCenterMenu->id)
            ->delete();

        $this->updateOrCreate(
            "admin_monitor.app_stats", $monitorCenterMenu->id, 40, "应用运行监控", "fa-area-chart");

        $this->updateOrCreate(
            "admin_monitor.location_ingress", $monitorCenterMenu->id, 45, "定位入口调试", "fa-exchange");

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
