<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Seeder;

use Illuminate\Database\Seeder;
use Mallto\Tool\Seeder\Menu\PageMenusSeeder;
use Mallto\Tool\Seeder\Menu\SmsNotifyMenuSeeder;
use Mallto\Tool\Seeder\Menu\BaseMenuSeeder;

class MenuTablesSeeder extends Seeder
{

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->call(SmsNotifyMenuSeeder::class);
        $this->call(BaseMenuSeeder::class);
        $this->call(PageMenusSeeder::class);

//DummySeeder

    }
}
