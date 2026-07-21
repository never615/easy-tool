<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Illuminate\Database\Eloquent\Model;

/**
 * AppSecretsRole：app secrets role 对应的数据模型或持久化边界。
 * 它集中声明字段、关联和查询语义，供领域服务与管理界面复用。
 */
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
