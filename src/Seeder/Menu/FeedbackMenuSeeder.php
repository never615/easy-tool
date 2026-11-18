<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Seeder\Menu;

use Illuminate\Database\Seeder;
use Mallto\Admin\Data\Menu;
use Mallto\Admin\Seeder\MenuSeederMaker;

/**
 * FeedbackMenuSeeder：初始化 feedback menu seeder 所需的基础数据。
 * 种子过程保持幂等，以便在安装、升级或测试环境中重复执行。
 */
class FeedbackMenuSeeder extends Seeder
{

    use MenuSeederMaker;

    /**
     * 填充数据库初始数据。
     *
     * @return void
     */
    public function run()
    {
        $order = 8;

        $operationMenu = $this->updateOrCreate("operation", 0,
            $order, "trans::admin.menu_titles.operation", "fa-anchor");
        $this->updateOrCreate("feedbacks.index", $operationMenu->id, $order++, "trans::admin.menu_titles.feedbacks", "fa-adjust");

    }
}
