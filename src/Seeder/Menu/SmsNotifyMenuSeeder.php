<?php

namespace Mallto\Tool\Seeder\Menu;

use Illuminate\Database\Seeder;
use Mallto\Admin\Data\Menu;
use Mallto\Admin\Seeder\MenuSeederMaker;


class SmsNotifyMenuSeeder extends Seeder
{
    use MenuSeederMaker;

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {

        $order = Menu::max('order');
        $parentId = 1;

        $menu = Menu::where("uri", "user_manager")->first();

        if ($menu) {
            $parentId = $menu->id;
        }
        $smsManagerMenu = $this->updateOrCreate(
            "sms_administ", $parentId, 6, "短信管理", "fa-500px");

        $parentId = $smsManagerMenu->id;

        $this->updateOrCreate(
            'sms_codes.index', $parentId, $order++, '短信验证码查询', 'fa-qrcode');

        $this->updateOrCreate(
            "sms_notifies.index", $parentId, $order++, "短信群发", "fa-500px");

        $this->updateOrCreate(
            'sms_templates.index', $parentId, $order++,
            '短信模板管理', 'fa-paper-plane-o');

    }
}
