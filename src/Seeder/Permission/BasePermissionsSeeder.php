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
        $this->createPermissions("队列诊断监控", "queue_diagnostics.index", false, 0);
        $this->createPermissions("队列诊断配置保存", "queue_diagnostics.settings", false, 0);
        $this->createPermissions("系统日志", "system_logs.index",false,0);
    }
}
