<?php

namespace Mallto\Tool\Seeder\Permission;

use Encore\Admin\Auth\Database\Permission;
use Illuminate\Database\Seeder;
use Mallto\Admin\Seeder\SeederMaker;

class SmsCodePermissionSeeder extends Seeder
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
        $this->createPermissions("短信验证码", "sms_codes", true, $parentId);
    }
}
