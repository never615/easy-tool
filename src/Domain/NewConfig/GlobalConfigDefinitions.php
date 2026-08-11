<?php

namespace Mallto\Tool\Domain\NewConfig;

use Mallto\Tool\Data\Config;

/**
 * 全局业务配置的代码侧定义目录。
 *
 * 初始清单由开发阶段的 AI 辅助源码扫描生成：盘点活跃业务代码中的
 * ConfigUtils::get() key，再将扫描结果固化为 PHP Definitions。后续新增或删除
 * ConfigUtils key 时仍应重新执行同类源码盘点并同步本目录。
 *
 * 运行时、应用启动和 tool:update 都不会扫描 PHP 源码；它们只消费已固化的
 * Definitions 数组以注册或同步配置元数据。
 */
class GlobalConfigDefinitions
{
    /**
     * 处理「modules」相关的内部协作步骤。
     */
    public static function modules(): array
    {
        return array_merge([
            'basic' => [
                'title' => '基础业务配置',
                'description' => 'App Secret / 调试日志 / API 日志',
                'route' => 'configs.basic',
                'save_route' => 'configs.basic.save',
            ],
            'sms' => [
                'title' => '短信与告警配置',
                'description' => '融合通信 / 系统告警',
                'route' => 'configs.sms',
                'save_route' => 'configs.sms.save',
            ],
        ], GlobalConfigRegistry::modules());
    }

    /**
     * 处理「definitions」相关的内部协作步骤。
     */
    public static function definitions(?string $module = null): array
    {
        $definitions = GlobalConfigDefinition::keyByConfigKey(array_merge(
            self::basicDefinitions(),
            self::smsDefinitions(),
            GlobalConfigRegistry::definitions()
        ));

        if ($module === null) {
            return $definitions;
        }

        return array_filter($definitions, function (array $definition) use ($module) {
            return ($definition['module'] ?? null) === $module;
        });
    }

    /**
     * 处理「module」相关的内部协作步骤。
     */
    public static function module(string $module): ?array
    {
        $modules = self::modules();

        return $modules[$module] ?? null;
    }

    public static function find(string $key): ?array
    {
        $registered = GlobalConfigRegistry::definition($key);
        if ($registered !== null) {
            return $registered;
        }

        static $builtIn = null;
        $builtIn ??= GlobalConfigDefinition::keyByConfigKey(array_merge(
            self::basicDefinitions(),
            self::smsDefinitions()
        ));

        return $builtIn[$key] ?? null;
    }

    /**
     * 处理「allowedModules」相关的内部协作步骤。
     */
    public static function allowedModules(): array
    {
        return array_keys(self::modules());
    }

    /**
     * 处理「basicDefinitions」相关的内部协作步骤。
     */
    private static function basicDefinitions(): array
    {
        return [
            self::definition(Config::APP_SECRET, '开放平台 App Secret', 'basic', '', 'string', '请求定位接口使用的签名密钥。'),
            self::definition('debug_log', '全局调试日志', 'basic', '0', 'boolean', '开启后部分业务会写额外调试日志。'),
            self::definition('close_owner_api_log_except', '关闭 Owner API 日志排除规则', 'basic', '0', 'boolean', '开启后 owner API 日志不再跳过 app.log.except 中的路径。'),
        ];
    }

    /**
     * 处理「smsDefinitions」相关的内部协作步骤。
     */
    private static function smsDefinitions(): array
    {
        return [
            self::definition('rh_sms_url', '融合通信接口地址', 'sms', '104.0.44.119:30020', 'string', '不包含最终发送路径，代码会追加 /api/v3.0/msg/send/direct。'),
            self::definition('rh_sms_account', '融合通信账号', 'sms', 'znwx', 'string', '融合通信 X-Auth 账号。'),
            self::definition('rh_authorization_code', '融合通信授权码', 'sms', 'pdEKIusgG9', 'string', '融合通信 X-Sign 授权码。'),
            self::definition('log_sms_template_code', '系统日志短信模板', 'sms', 'API-ZWX-00001', 'string', '系统异常日志短信通知模板。'),
            self::definition('system_alarm_contact', '系统报警联系人', 'sms', '', 'string', '多个手机号用英文逗号分隔。'),
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
        ?string $envKey = null,
        array $meta = []
    ): array {
        return GlobalConfigDefinition::make(
            $key,
            $name,
            $module,
            $defaultValue,
            $type,
            $remark,
            $envKey,
            array_merge([
                'sort' => self::sortFor($module),
            ], $meta)
        );
    }

    /**
     * 处理「sortFor」相关的内部协作步骤。
     */
    private static function sortFor(string $module): int
    {
        $orders = array_flip(self::allowedModules());

        return (($orders[$module] ?? 0) + 1) * 1000;
    }
}
