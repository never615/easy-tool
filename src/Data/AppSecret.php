<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Mallto\Admin\Data\Subject;
use Mallto\Tool\Data\Traits\ThirdCheck;

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
