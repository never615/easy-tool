<?php

namespace Mallto\Tool\Domain\SubjectConfig;

use Mallto\Admin\Domain\SubjectConfig\SubjectConfigApplyMode;
use Mallto\Admin\Domain\SubjectConfig\SubjectConfigDefinition;

/**
 * ToolSubjectConfigDefinitions：easy-tool 的 tool subject config definitions 组件。
 * 它通过明确的职责边界供包内其他模块复用。
 */
class ToolSubjectConfigDefinitions
{
    /**
     * 处理「modules」相关的内部协作步骤。
     */
    public static function modules(): array
    {
        return [
            'basic_front' => [
                'title' => '基础与前端',
                'description' => '短信、帮助文档、前端统计、前端接口域名等项目级配置。',
            ],
        ];
    }

    /**
     * 处理「definitions」相关的内部协作步骤。
     */
    public static function definitions(): array
    {
        return [
            self::definition('sms_sign', '短信签名', 'basic_front', '', 'string', '项目短信签名；不配置时业务代码使用默认签名。'),
            self::definition('send_sms_code', '短信验证码发送开关', 'basic_front', '1', 'boolean', '关闭后项目短信验证码不发送。'),
            self::definition('wiki', '帮助文档地址', 'basic_front', '', 'string', '管理端底部帮助文档链接。', self::sharedRedisMeta()),
            self::definition('cdn_backend_domain', '后端接口 CDN 域名', 'basic_front', '', 'string', '前端初始化接口返回的后端接口 CDN 加速域名。', self::sharedRedisMeta()),
            self::definition('statistics_pid', '阿里云前端统计 PID', 'basic_front', '', 'string', '前端统计服务使用的 project id。', self::sharedRedisMeta()),
            self::definition('statistics_project', '阿里云日志项目', 'basic_front', 'web_log', 'string', '阿里云日志上报 project。', self::sharedRedisMeta()),
        ];
    }

    /**
     * 处理「definition」相关的内部协作步骤。
     */
    private static function definition(
        string $key,
        string $name,
        string $module,
        string $defaultValue,
        string $type,
        string $remark,
        array $meta = []
    ): array {
        return SubjectConfigDefinition::make($key, $name, $module, $defaultValue, $type, $remark, $meta);
    }

    private static function sharedRedisMeta(): array
    {
        return ['apply_mode' => SubjectConfigApplyMode::SHARED_REDIS];
    }
}
