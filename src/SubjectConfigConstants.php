<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool;

/**
 * SubjectConfigConstants：easy-tool 的 subject config constants 组件。
 * 它通过明确的职责边界供包内其他模块复用。
 */
class SubjectConfigConstants extends \Mallto\Admin\SubjectConfigConstants
{

    //短信
    const SMS_SYSTEM = 'sms_system';

    //短信验证模板code
    const OWNER_CONFIG_SMS_TEMPLATE_CODE = "sms_template_code";

    //短信签名
    const OWNER_CONFIG_SMS_SIGN = "sms_sign";
}
