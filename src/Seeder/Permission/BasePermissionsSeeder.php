<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Seeder\Permission;

use Illuminate\Database\Seeder;
use Mallto\Admin\Seeder\SeederMaker;

/**
 * BasePermissionsSeeder：初始化 base permissions seeder 所需的基础数据。
 * 种子过程保持幂等，以便在安装、升级或测试环境中重复执行。
 */
class BasePermissionsSeeder extends Seeder
{

    use SeederMaker;

    /**
     * Run the database seeds.
     *
     * @return void
     * @throws \Exception
     */
    public function run()
    {
        $this->createPermissions("微信模板消息管理", "wechat_template_ids");
        $this->createPermissions("全局配置", "configs");
        $this->createPermissions("基础业务配置", "configs.basic", false, 0);
        $this->createPermissions("基础业务配置保存", "configs.basic.save", false, 0);
        $this->createPermissions("短信与告警配置", "configs.sms", false, 0);
        $this->createPermissions("短信与告警配置保存", "configs.sms.save", false, 0);
        $this->createPermissions("配置中心", "new_configs");
        $this->createPermissions("配置中心使用说明", "new_configs.usage", false, 0);
        $this->createPermissions("配置中心发布启动配置", "new_configs.publish_restart", false, 0);
        $this->createPermissions("配置中心 Env 预览", "new_configs.env_preview", false, 0);
        $this->createPermissions("配置中心手动重启", "new_configs.reload", false, 0);
        $this->createPermissions("Swoole Task配置", "new_configs.swoole_task_monitor", false, 0);
        $this->createPermissions("Swoole Task配置保存", "new_configs.swoole_task_monitor.save", false, 0);
        $this->createPermissions("运行期 Env 覆盖", "new_configs.runtime_env_overrides", false, 0);
        $this->createPermissions("队列诊断监控", "queue_diagnostics.index", false, 0);
        $this->createPermissions("队列诊断配置保存", "queue_diagnostics.settings", false, 0);
        $this->createPermissions("Swoole Task监控", "swoole_task_monitor.index", false, 0);
        $this->createPermissions("Swoole Task监控重置", "swoole_task_monitor.reset", false, 0);
        $this->createPermissions("horizon 监控", "admin_monitor.horizon", false, 0);
        $this->createPermissions("应用运行监控", "admin_monitor.app_stats", false, 0);
        $this->createPermissions("应用运行监控数据", "admin_monitor.app_stats.data", false, 0);
        $this->createPermissions("应用运行监控临时文件清理", "admin_monitor.app_stats.task_tmp_cleanup", false, 0);
        $this->createPermissions("定位诊断中心", "admin_monitor.location_ingress", false, 0);
        $this->createPermissions("定位诊断中心数据", "admin_monitor.location_ingress.data", false, 0);
        $this->createPermissions("定位入口调试开关", "admin_monitor.location_ingress.switch", false, 0);
        $this->createPermissions("定位诊断模式开关", "admin_monitor.location_ingress.mode", false, 0);
        $this->createPermissions("定位诊断明细", "location_ingress_trace.index", false, 0);
        /*
         * 旧路由仍为现有 Grafana/JSON 调用、历史角色授权和内部链接提供兼容入口。
         * 本期新增 AppStats 权限时不能删除它，否则升级后旧调用会在路由仍存在的情况下被拒绝。
         */
        $this->createPermissions("swoole 监控兼容入口", "admin_monitor.swoole_stats", false, 0);
        $this->createPermissions("第三方接口通讯日志", "third_logs");
        $this->createPermissions("自己上报的日志", "owner_logs");
        $this->createPermissions("项目日志", "system_logs.index", false, 0);
    }
}
