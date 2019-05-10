<?php

namespace Mallto\Tool\Seeder\Permission;

use Illuminate\Database\Seeder;
use Mallto\Admin\Seeder\SeederMaker;
use Encore\Admin\Auth\Database\Permission;


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
        $parentId=0;
        $parentPermisson=Permission::where("slug","")->first();
        if($parentPermisson){
            $parentId=$parentPermisson->id;
        }
        $this->createPermissions("短信模板管理", "sms_templates",true,$parentId);
    }
}
