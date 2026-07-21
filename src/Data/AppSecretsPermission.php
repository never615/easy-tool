<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Illuminate\Database\Eloquent\Model;

/**
 * AppSecretsPermission：app secrets permission 对应的数据模型或持久化边界。
 * 它集中声明字段、关联和查询语义，供领域服务与管理界面复用。
 */
class AppSecretsPermission extends BaseModel
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
