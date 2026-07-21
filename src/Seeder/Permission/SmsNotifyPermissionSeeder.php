<?php

namespace Mallto\Tool\Seeder\Permission;

use Illuminate\Database\Seeder;
use Mallto\Admin\Seeder\SeederMaker;

/**
 * SmsNotifyPermissionSeeder：初始化 sms notify permission seeder 所需的基础数据。
 * 种子过程保持幂等，以便在安装、升级或测试环境中重复执行。
 */
class SmsNotifyPermissionSeeder extends Seeder
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
        $this->createPermissions("短信群发", "sms_notifies");
    }
}
