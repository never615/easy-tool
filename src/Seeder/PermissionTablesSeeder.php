<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Seeder;

use Illuminate\Database\Seeder;
use Mallto\Admin\Seeder\SeederMaker;

class PermissionTablesSeeder extends Seeder
{

    use SeederMaker;


    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
    $this->call(\Mallto\Tool\Seeder\Permission\SmsNotifySeeder::class);
//DummySeeder

    }
}
