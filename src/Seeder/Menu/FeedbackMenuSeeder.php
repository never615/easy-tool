<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Seeder\Menu;

use Illuminate\Database\Seeder;
use Mallto\Admin\Data\Menu;
use Mallto\Admin\Seeder\MenuSeederMaker;

class FeedbackMenuSeeder extends Seeder
{

    use MenuSeederMaker;

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $order = 8;

        $operationMenu = $this->updateOrCreate("operation", 0,
            $order, "运营", "fa-anchor");
        $this->updateOrCreate("feedbacks.index", $operationMenu->id, $order++, "意见反馈", "fa-adjust");

    }
}
