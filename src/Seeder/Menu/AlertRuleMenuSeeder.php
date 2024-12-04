<?php

namespace Mallto\Tool\Seeder\Menu;

use Illuminate\Database\Seeder;
use Mallto\Admin\Data\Menu;
use Mallto\Admin\Seeder\MenuSeederMaker;

class AlertRuleMenuSeeder extends Seeder
{
    use MenuSeederMaker;

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $menu = Menu::where("uri", "system_manager")->first();

        $order = Menu::max('order');
        $parentId = 0;
        if ($menu) {
            $order = $menu->order;
            $parentId = $menu->id;
        }

        $this->updateOrCreate(
            'alert_rules.index', $parentId, $order++, '报警配置', 'fa-warning');
    }
}
