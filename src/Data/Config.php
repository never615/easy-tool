<?php

namespace Mallto\Tool\Data;

use Mallto\Admin\Data\Traits\BaseModel;

/**
 * Config：旧 `configs` 表的只读迁移模型。
 *
 * 全局业务配置的运行期唯一数据源已经迁移为 NewConfig（`new_configs` 表）。
 * 本模型仅由 ConfigTablesSeeder 在升级/初始化时读取旧数据并补齐缺失的新记录；
 * 禁止在新的运行期读写路径、后台页面或业务代码中继续使用它。
 */
class Config extends BaseModel
{

    const APP_SECRET = 'app_secret';
    const ALIYUN_SMS_TEMPLATE_CODES = 'aliyun_sms_template_codes';

}
