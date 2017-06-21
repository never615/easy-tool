<?php

namespace Mallto\Tool\Seeder;

use Encore\Admin\Auth\Database\Menu;
use Illuminate\Database\Seeder;
use Mallto\Mall\Data\AdminUser;
use Mallto\Mall\Data\Subject;

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

        $order = Menu::orderBy("order", "desc")->first()->order;

        $tempMenu = Menu::where("title", "第三方日志")->get();
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
