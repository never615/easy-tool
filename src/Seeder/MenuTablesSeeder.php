<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Seeder;

use Illuminate\Database\Seeder;
use Mallto\Tool\Seeder\Menu\ApiPvManagerMenuSeeder;
use Mallto\Tool\Seeder\Menu\BaseMenuSeeder;
use Mallto\Tool\Seeder\Menu\FeedbackMenuSeeder;
use Mallto\Tool\Seeder\Menu\OwnerLogMenuSeeder;
use Mallto\Tool\Seeder\Menu\PagePvManagerMenuSeeder;
use Mallto\Tool\Seeder\Menu\SmsNotifyMenuSeeder;

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
        $this->call(ApiPvManagerMenuSeeder::class);
        $this->call(PagePvManagerMenuSeeder::class);
        $this->call(OwnerLogMenuSeeder::class);
        $this->call(FeedbackMenuSeeder::class);
        $this->call(\Mallto\Tool\Seeder\Menu\AlertRuleMenuSeeder::class);
//DummySeeder

    }
}
