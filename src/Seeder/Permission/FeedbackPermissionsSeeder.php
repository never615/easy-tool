<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Seeder\Permission;

use Illuminate\Database\Seeder;
use Mallto\Admin\Seeder\SeederMaker;

/**
 * FeedbackPermissionsSeeder：初始化 feedback permissions seeder 所需的基础数据。
 * 种子过程保持幂等，以便在安装、升级或测试环境中重复执行。
 */
class FeedbackPermissionsSeeder extends Seeder
{

    use SeederMaker;

    /**
     * 填充数据库初始数据。
     *
     * @return void
     * @throws \Exception
     */
    public function run()
    {
        $this->createPermissions("意见反馈", "feedbacks");
    }
}
