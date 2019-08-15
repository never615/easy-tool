<?php

namespace Mallto\Tool\Seeder\Menu;

use Mallto\Admin\Data\Menu;
use Illuminate\Database\Seeder;
use Mallto\Admin\Seeder\MenuSeederMaker;

class SmsCodeMenuSeeder extends Seeder
{
    use MenuSeederMaker;

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $menu = Menu::where("uri", "user_manager")->first();

        $order=Menu::max('order');
        $parentId=0;
        if($menu){
            $order=$menu->order;
            $parentId=$menu->id;
        }

    }
}
