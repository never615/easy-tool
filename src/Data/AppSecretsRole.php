<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Illuminate\Database\Eloquent\Model;

class AppSecretsRole extends BaseModel
{

    protected $guarded = [
    ];


    /**
     * 角色关联开发者
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function appSecrets()
    {
        return $this->belongsToMany(AppSecret::class, 'app_secrets_has_roles', 'role_id', 'app_secret_id');
    }


    /**
     * 关联权限
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function permissions()
    {
        return $this->belongsToMany(AppSecretsPermission::class, 'app_secrets_role_has_permissions',
            'role_id', 'permission_id');
    }

}
