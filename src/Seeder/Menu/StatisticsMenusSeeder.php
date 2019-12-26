<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Seeder\Menu;

use Encore\Admin\Auth\Database\Menu;
use Illuminate\Database\Seeder;
use Mallto\Admin\Seeder\MenuSeederMaker;

/**
 * Class StatisticsMenusSeeder
 *
 * @package Mallto\Tool\Seeder\Menu
 */
class StatisticsMenusSeeder extends Seeder
{

    use MenuSeederMaker;


    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {

        $order = Menu::max('order');

        $statisticsManagerMenu = $this->updateOrCreate(
            "statistics_manager", 0, $order++, "统计分析", "fa-area-chart");

        $this->updateOrCreate(
            "pv.index", $statisticsManagerMenu->id, $order++, "访问统计", "fa-fire");

    }
}
