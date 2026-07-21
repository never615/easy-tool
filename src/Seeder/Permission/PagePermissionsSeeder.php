<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Seeder\Permission;

use Illuminate\Database\Seeder;
use Mallto\Admin\Data\Permission;
use Mallto\Admin\Seeder\SeederMaker;

/**
 * PagePermissionsSeeder：初始化 page permissions seeder 所需的基础数据。
 * 种子过程保持幂等，以便在安装、升级或测试环境中重复执行。
 */
class PagePermissionsSeeder extends Seeder
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
//        $parentId = $this->createPermissions("页面管理", "page", false);
        Permission::where("slug", "page")->delete();
        $parentId = 0;
        $this->createPermissions("页面广告", "ads", true, $parentId);
        //轮播图模块今后会废弃
        $this->createPermissions("轮播图", "page_banners", true, $parentId);
    }
}
