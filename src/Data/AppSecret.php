<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Mallto\Admin\Data\Subject;
use Mallto\Tool\Data\Traits\ThirdCheck;

/**
 * AppSecret：app secret 对应的数据模型或持久化边界。
 * 它集中声明字段、关联和查询语义，供领域服务与管理界面复用。
 */
class AppSecret extends BaseModel
{

    use ThirdCheck;

    protected $guarded = [
    ];


    /**
     * 开发者关联角色
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function roles()
    {
        return $this->belongsToMany(AppSecretsRole::class, 'app_secrets_has_roles', 'app_secret_id',
            'role_id');
    }

    /**
     * 开发者关联主体
     */
    public function app_secret_subjects()
    {
        return $this->belongsToMany(Subject::class, 'app_secrets_has_subjects', 'app_secret_id', 'subject_id');
    }
}
