<?php

namespace Mallto\Tool\Domain\Log;

use Mallto\Tool\Domain\NewConfig\GlobalConfigApplyMode;
use Mallto\Tool\Domain\NewConfig\GlobalConfigDefinition;
use Mallto\Tool\Domain\NewConfig\GlobalConfigRegistry;

/** 入站日志页面与统一配置中心共享同一组全局配置，不另建开关。 */
class OwnerApiLogConfigDefinitions
{
    public static function register(): void
    {
        // 后台继承值与部署 include 保持一致，也覆盖直接修改 PHP 数组的部署方式。
        config(['owner_api_log.admin_rules' => array_values(array_map(
            static fn (string $path): array => ['path' => $path, 'enabled' => true],
            array_filter((array) config('owner_api_log.include', []), 'is_string')
        ))]);
        GlobalConfigRegistry::registerModule('owner_api_log', [
            'title' => '入站接口日志',
            'description' => '采集开关、路径白名单和日志保留天数',
            'route' => 'owner_api_log.settings',
            'save_route' => 'owner_api_log.save',
        ]);
        GlobalConfigRegistry::registerDefinitions([
            GlobalConfigDefinition::fromConfig(
                'owner_api_log.enabled', '是否启用入站接口日志', 'owner_api_log',
                'app.log.owner_api', 'boolean',
                '与接口日志规则页面共用此开关；未覆盖时继承 config/.env，显式关闭始终生效。',
                'OWNER_API_LOG', ['apply_mode' => GlobalConfigApplyMode::SWOOLE_REDIS]
            ),
            GlobalConfigDefinition::fromConfig(
                'owner_api_log.rules', '接口日志白名单规则', 'owner_api_log',
                'owner_api_log.admin_rules', 'json',
                '通过接口日志规则页面维护路径和每条规则的启用状态；静态排除的高频接口始终跳过。',
                null, ['apply_mode' => GlobalConfigApplyMode::SWOOLE_REDIS]
            ),
            GlobalConfigDefinition::fromConfig(
                'owner_api_log.retention_days', '入站接口日志保留天数', 'owner_api_log',
                'owner_api_log.retention_days', 'integer',
                '每天删除超过保留天数的本地入站日志；允许 1–36500 天，默认 7 天。',
                'OWNER_API_LOG_RETENTION_DAYS', ['apply_mode' => GlobalConfigApplyMode::SWOOLE_REDIS]
            ),
        ]);
    }
}
