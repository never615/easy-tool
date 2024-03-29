<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Illuminate\Database\Eloquent\Model;
use Mallto\Tool\Data\Traits\ThirdCheck;

class AppSecret extends Model
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
}
