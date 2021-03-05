<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Illuminate\Database\Eloquent\Model;

class AppSecretsPermission extends Model
{

    protected $guarded = [
    ];


    /**
     * 权限管理角色
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function roles()
    {
        return $this->belongsToMany(AppSecretsRole::class, 'app_secrets_role_has_permissions',
            'permission_id', 'role_id');
    }
}
