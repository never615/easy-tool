<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Seeder;

use Illuminate\Database\Seeder;
use Mallto\Admin\Seeder\SeederMaker;
use Mallto\Tool\Seeder\Menu\SmsNotifyMenuSeeder;
use Mallto\Tool\Seeder\Menu\WechatTemplateMenuSeeder;

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
    $this->call(WechatTemplateMenuSeeder::class);
//DummySeeder

    }
}
