<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Seeder;

use Illuminate\Database\Seeder;
use Mallto\Tool\Seeder\Menu\WechatTemplateSeeder;

class TablesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->call(WechatTemplateSeeder::class);
$this->call(\Mallto\Tool\Seeder\Permission\SmsNotifySeeder::class);
$this->call(\Mallto\Tool\Seeder\Menu\SmsNotifySeeder::class);
//DummySeeder
    }
}
