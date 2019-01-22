<?php

namespace Mallto\Tool\Seeder\Menu;

use Mallto\Admin\Data\Menu;
use Illuminate\Database\Seeder;


class SmsTemplateMenuSeeder extends Seeder
{
    use MenuSeederMaker;

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $menu = Menu::where("uri", "admin_manager")->first();

        $order=Menu::max('order');
        $parentId=0;
        if($menu){
            $order=$menu->order;
            $parentId=$menu->id;
        }

        $this->updateOrCreate(
                    'sms_templates.index', $parentId, $order++, '短信模板管理', 'fa-paper-plane-o');
    }
}
