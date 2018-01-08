<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Seeder;

use Encore\Admin\Auth\Database\Menu;
use Illuminate\Database\Seeder;

class MenuTablesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $menu = Menu::where("title", "管理")->first();

        if (!$menu) {
            $menu = Menu::create([
                'parent_id' => 0,
                'order'     => $order = 2000,
                'title'     => '管理',
                'icon'      => 'fa-tasks',
                'uri'       => '',
            ]);
        }

        $firstMenu = Menu::orderBy("order", "desc")->first();
        if ($firstMenu) {
            $order = $firstMenu->order;
        } else {
            $order = 2000;
        }

        $tempMenu = Menu::where("title", "第三方日志")->first();
        if ($tempMenu) {
            return;
        }

        if ($menu) {
            Menu::insert([
                [
                    'parent_id' => $menu->id,
                    'order'     => $order += 1,
                    'title'     => '第三方日志',
                    'icon'      => 'fa-history',
                    'uri'       => 'third_logs.index',
                ],
            ]);
        }
    }
}
