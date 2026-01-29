<?php
/*
 * Copyright (c) 2023. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Seeder\Permission\Tp;

use Mallto\Admin\Seeder\SeederMaker;
use Mallto\Tool\Data\AppSecretsPermission;

/**
 * User: never615 <never615.com>
 * Date: 2023/12/20
 * Time: 17:01
 */
class TpApiPermissionBaseSeeder extends \Illuminate\Database\Seeder
{
    use SeederMaker;

    // 是否全局生成子权限

    public function __construct()
    {
        $this->setRouteNamePrefix('tp_api');
        $this->setModel(AppSecretsPermission::class);
        $this->setGlobalSub(false);
    }
}