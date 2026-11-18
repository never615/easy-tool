<?php
/**
 * Copyright () 2018. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Seeder\Menu;

use Illuminate\Database\Seeder;
use Mallto\Admin\Data\Menu;
use Mallto\Admin\Seeder\MenuSeederMaker;

/**
 * ApiPvManagerMenuSeeder：初始化 api pv manager menu seeder 所需的基础数据。
 * 种子过程保持幂等，以便在安装、升级或测试环境中重复执行。
 */
class ApiPvManagerMenuSeeder extends Seeder
{

    use MenuSeederMaker;


    /**
     * 填充数据库初始数据。
     *
     * @return void
     */
    public function run()
    {
        $order = Menu::max('order');
        $parentId = 0;

        $menu = Menu::where("uri", "system_manager")->first();

        if ($menu) {
            $order = $menu->order;
            $parentId = $menu->id;
        }

        $this->updateOrCreate(
            "api_pv_managers.index", $parentId, $order++, "api pv管理", "fa-line-chart");
    }
}
