<?php

namespace Mallto\Tool\Seeder\Menu;

use Illuminate\Database\Seeder;
use Mallto\Admin\Data\Menu;


class SmsNotifyMenuSeeder extends Seeder
{

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {

        $menu = Menu::where("uri", "user_manager")->first();

        $order = 10000;
        $parentId = 0;
        if ($menu) {
            $order = $menu->order;
            $parentId = $menu->id;
        }

        Menu::updateOrCreate([
            'uri' => 'sms_notifies.index',
        ], [
                'parent_id' => $parentId,
                'order'     => $order += 1,
                'title'     => '短信群发',
                'icon'      => 'fa-500px',
            ]
        );

    }
}
