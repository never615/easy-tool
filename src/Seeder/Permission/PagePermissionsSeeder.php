<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Seeder\Permission;


use Illuminate\Database\Seeder;
use Mallto\Admin\Data\Permission;
use Mallto\Admin\Seeder\SeederMaker;

class PagePermissionsSeeder extends Seeder
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
//        $parentId = $this->createPermissions("页面管理", "page", false);
        Permission::where("slug", "page")->delete();
        $parentId = 0;
        $this->createPermissions("页面广告", "ads", true, $parentId);
        //轮播图模块今后会废弃
        $this->createPermissions("轮播图", "page_banners", true, $parentId);
    }
}
