<?php

namespace Mallto\Tool\Seeder\Permission;

use Illuminate\Database\Seeder;
use Mallto\Admin\Seeder\SeederMaker;
use Encore\Admin\Auth\Database\Permission;


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
