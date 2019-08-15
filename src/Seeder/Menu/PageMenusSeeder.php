<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Seeder\Menu;


use Encore\Admin\Auth\Database\Menu;
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
        $order = Menu::max('order');


    }
}
