<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Seeder;

use Illuminate\Database\Seeder;
use Mallto\Tool\Seeder\Menu\ApiPvManagerMenuSeeder;
use Mallto\Tool\Seeder\Menu\BaseMenuSeeder;
use Mallto\Tool\Seeder\Menu\ConfigMenuSeeder;
use Mallto\Tool\Seeder\Menu\FeedbackMenuSeeder;
use Mallto\Tool\Seeder\Menu\PagePvManagerMenuSeeder;
use Mallto\Tool\Seeder\Menu\SmsNotifyMenuSeeder;

/**
 * MenuTablesSeeder：初始化 menu tables seeder 所需的基础数据。
 * 种子过程保持幂等，以便在安装、升级或测试环境中重复执行。
 */
class MenuTablesSeeder extends Seeder
{

    /**
     * 填充数据库初始数据。
     *
     * @return void
     */
    public function run()
    {
        $this->call(SmsNotifyMenuSeeder::class);
        $this->call(BaseMenuSeeder::class);
        $this->call(ApiPvManagerMenuSeeder::class);
        $this->call(PagePvManagerMenuSeeder::class);
        $this->call(ConfigMenuSeeder::class);
        $this->call(FeedbackMenuSeeder::class);
        $this->call(\Mallto\Tool\Seeder\Menu\AlertRuleMenuSeeder::class);
//填充器占位符

    }
}
