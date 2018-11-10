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

    protected $order = 2000;

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->createPermissions("微信模板消息管理", "wechat_template_ids");
    }
}
