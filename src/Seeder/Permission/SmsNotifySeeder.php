<?php

namespace Mallto\Tool\Seeder\Permission;

use Illuminate\Database\Seeder;
use Mallto\Admin\Seeder\SeederMaker;


class SmsNotifySeeder extends Seeder
{

    use SeederMaker;

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->createPermissions("短信群发", "sms_notifies");
    }
}
