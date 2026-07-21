<?php

namespace Mallto\Tool\Seeder\Permission;

use Encore\Admin\Auth\Database\Permission;
use Illuminate\Database\Seeder;
use Mallto\Admin\Seeder\SeederMaker;

/**
 * SmsTemplatePermissionSeeder：初始化 sms template permission seeder 所需的基础数据。
 * 种子过程保持幂等，以便在安装、升级或测试环境中重复执行。
 */
class SmsTemplatePermissionSeeder extends Seeder
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
        $parentId = 0;
        $parentPermisson = Permission::where("slug", "")->first();
        if ($parentPermisson) {
            $parentId = $parentPermisson->id;
        }
        $this->createPermissions("短信模板管理", "sms_templates", true, $parentId);
        $this->createPermissions("卡券短信管理", "coupon_sms_templates");
    }
}
