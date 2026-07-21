<?php

namespace Mallto\Tool\Data;

use Mallto\Admin\Data\Traits\BaseModel;

/**
 * AppSecretsHasSubject：app secrets has subject 对应的数据模型或持久化边界。
 * 它集中声明字段、关联和查询语义，供领域服务与管理界面复用。
 */
class AppSecretsHasSubject extends BaseModel
{
    /**
     * 处理「appSecrets」相关的内部协作步骤。
     */
    public function appSecrets()
    {
        return $this->belongsToMany(AppSecret::class, 'app_secrets_has_subjects', 'subject_id', 'app_secret_id');
    }
}
