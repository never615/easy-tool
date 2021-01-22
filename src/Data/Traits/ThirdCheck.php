<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data\Traits;

use Mallto\Tool\Data\AppSecretsPermission;

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

        return AppSecretsPermission::query()
            ->whereHas('roles', function ($query) use ($roles) {
                $query->whereIn('role_id', $roles);
            })
            ->where('slug', $permission)
            ->exists();
    }

}
