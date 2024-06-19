<?php

namespace Mallto\Tool\Data;

use Mallto\Admin\Data\Traits\BaseModel;

class AppSecretsHasSubject extends BaseModel
{
    public function appSecrets()
    {
        return $this->belongsToMany(AppSecret::class, 'app_secrets_has_subjects', 'subject_id', 'app_secret_id');
    }
}
