<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Seeder\Permission;

use Illuminate\Database\Seeder;
use Mallto\Admin\Seeder\SeederMaker;

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
        $this->createPermissions("定位算法配置", "configs.location_algorithm", false, 0);
        $this->createPermissions("定位算法配置保存", "configs.location_algorithm.save", false, 0);
        $this->createPermissions("定位维护配置", "configs.location_maintenance", false, 0);
        $this->createPermissions("定位维护配置保存", "configs.location_maintenance.save", false, 0);
        $this->createPermissions("BeaconArea配置", "configs.beacon_area", false, 0);
        $this->createPermissions("BeaconArea配置保存", "configs.beacon_area.save", false, 0);
        $this->createPermissions("定位日志配置", "configs.location_debug", false, 0);
        $this->createPermissions("定位日志配置保存", "configs.location_debug.save", false, 0);
        $this->createPermissions("配置中心", "new_configs");
        $this->createPermissions("配置中心 Env 预览", "new_configs.env_preview", false, 0);
        $this->createPermissions("配置中心手动重启", "new_configs.reload", false, 0);
        $this->createPermissions("Swoole Task配置", "new_configs.swoole_task_monitor", false, 0);
        $this->createPermissions("Swoole Task配置保存", "new_configs.swoole_task_monitor.save", false, 0);
        $this->createPermissions("队列诊断监控", "queue_diagnostics.index", false, 0);
        $this->createPermissions("队列诊断配置保存", "queue_diagnostics.settings", false, 0);
        $this->createPermissions("Swoole Task监控", "swoole_task_monitor.index", false, 0);
        $this->createPermissions("Swoole Task监控重置", "swoole_task_monitor.reset", false, 0);
        $this->createPermissions("系统日志", "system_logs.index",false,0);
    }
}
