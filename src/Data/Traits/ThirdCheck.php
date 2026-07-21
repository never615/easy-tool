<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data\Traits;

use Mallto\Tool\Data\AppSecretsPermission;

/**
 * ThirdCheck：third check 对应的数据模型或持久化边界。
 * 它集中声明字段、关联和查询语义，供领域服务与管理界面复用。
 */
trait ThirdCheck
{

    /**
     * 权限检查
     *
     * @param string $permission
     *
     * @return bool
     */
    public function check(string $permission): bool
    {
        $roles = $this->roles()->pluck('id')->toArray();

        if (empty($roles)) {
            //兼容线上没有配置角色的开发者,允许全部权限
            return true;
        }

        return AppSecretsPermission::query()
            ->whereHas('roles', function ($query) use ($roles) {
                $query->whereIn('role_id', $roles);
            })
            ->where('slug', $permission)
            ->exists();
    }

}
