<?php

namespace Mallto\Tool\Seeder\Menu;

use Illuminate\Database\Seeder;
use Mallto\Admin\Data\Menu;
use Mallto\Admin\Seeder\MenuSeederMaker;

/**
 * AlertRuleMenuSeeder：初始化 alert rule menu seeder 所需的基础数据。
 * 种子过程保持幂等，以便在安装、升级或测试环境中重复执行。
 */
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
