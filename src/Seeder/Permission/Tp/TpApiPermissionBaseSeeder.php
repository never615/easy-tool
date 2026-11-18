<?php
/*
 * 版权所有 (c) 2023. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Seeder\Permission\Tp;

use Mallto\Admin\Seeder\SeederMaker;
use Mallto\Tool\Data\AppSecretsPermission;

/**
 * 作者： never615 <never615.com>
 * 日期： 2023/12/20
 * 时间： 17:01
 */
class TpApiPermissionBaseSeeder extends \Illuminate\Database\Seeder
{
    use SeederMaker;

    // 是否全局生成子权限

    /**
     * 注入本组件运行所需的协作依赖，保持构造与业务执行解耦。
     */
    public function __construct()
    {
        $this->setRouteNamePrefix('tp_api');
        $this->setModel(AppSecretsPermission::class);
        $this->setGlobalSub(false);
    }
}
