<?php

namespace Mallto\Tool\Seeder\Permission;

use Illuminate\Database\Seeder;
use Mallto\Admin\Seeder\SeederMaker;
use Encore\Admin\Auth\Database\Permission;


/**
 * AlertRulePermissionSeeder：初始化 alert rule permission seeder 所需的基础数据。
 * 种子过程保持幂等，以便在安装、升级或测试环境中重复执行。
 */
class AlertRulePermissionSeeder extends Seeder
{

    use SeederMaker;

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $parentId=0;
        $parentPermisson=Permission::where("slug","")->first();
        if($parentPermisson){
            $parentId=$parentPermisson->id;
        }
        $this->createPermissions("报警配置", "alert_rules",true,$parentId);
    }
}
