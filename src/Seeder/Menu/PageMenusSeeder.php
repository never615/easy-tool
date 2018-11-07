<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Seeder\Menu;


use Illuminate\Database\Seeder;
use Mallto\Admin\Seeder\MenuSeederMaker;

class PageMenusSeeder extends Seeder
{

    use MenuSeederMaker;

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $order = 10000;

        $pageManagerMenu = $this->updateOrCreate(
            "page_manager", 0, $order++, "页面配置", "fa-pagelines");


        $this->updateOrCreate(
            "page_banners.index", $pageManagerMenu->id, $order++, "轮播图", "fa-image");

        $this->updateOrCreate(
            "ads.index", $pageManagerMenu->id, $order++, "页面广告", "fa-headphones");
    }
}
