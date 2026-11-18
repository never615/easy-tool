<?php
/**
 * Copyright () 2018. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Seeder\Menu;

use Illuminate\Database\Seeder;
use Mallto\Admin\Data\Menu;
use Mallto\Admin\Seeder\MenuSeederMaker;

/**
 * PagePvManagerMenuSeeder：初始化 page pv manager menu seeder 所需的基础数据。
 * 种子过程保持幂等，以便在安装、升级或测试环境中重复执行。
 */
class PagePvManagerMenuSeeder extends Seeder
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
            "page_pv_manager.index", $parentId, $order++, "微信页面管理", "fa-line-chart");
    }
}
