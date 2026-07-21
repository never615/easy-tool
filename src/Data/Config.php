<?php

namespace Mallto\Tool\Data;

use Mallto\Admin\Data\Traits\BaseModel;

/**
 * Config：config 对应的数据模型或持久化边界。
 * 它集中声明字段、关联和查询语义，供领域服务与管理界面复用。
 */
class Config extends BaseModel
{

    const APP_SECRET = 'app_secret';
    const ALIYUN_SMS_TEMPLATE_CODES = 'aliyun_sms_template_codes';
    const HYTERA_DMR_MOCK_LOCATOR_NON_ERROR_LOG = 'hytera_dmr_mock_locator_non_error_log';

}
