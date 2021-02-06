<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Seeder;

use Illuminate\Database\Seeder;
use Mallto\Tool\Seeder\Permission\AppSecretPermissionsSeeder;
use Mallto\Tool\Seeder\Permission\BasePermissionsSeeder;
use Mallto\Tool\Seeder\Permission\PagePermissionsSeeder;
use Mallto\Tool\Seeder\Permission\StatisticsPermissionsSeeder;

class PermissionTablesSeeder extends Seeder
{

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->call(\Mallto\Tool\Seeder\Permission\SmsNotifyPermissionSeeder::class);
        $this->call(PagePermissionsSeeder::class);
        $this->call(BasePermissionsSeeder::class);
        $this->call(StatisticsPermissionsSeeder::class);

        $this->call(\Mallto\Tool\Seeder\Permission\SmsTemplatePermissionSeeder::class);
        $this->call(\Mallto\Tool\Seeder\Permission\SmsCodePermissionSeeder::class);
//DummySeeder

    }
}
