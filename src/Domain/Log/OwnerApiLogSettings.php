<?php

namespace Mallto\Tool\Domain\Log;

use Mallto\Tool\Domain\NewConfig\GlobalConfigRuntimeResolver;

/** 共用配置中心的 Swoole L1 / Redis 缓存，不创建新的逐请求数据库读取。 */
class OwnerApiLogSettings
{
    public function forRequest(): array
    {
        $values = app(GlobalConfigRuntimeResolver::class)->getMany([
            'owner_api_log.enabled' => config('app.log.owner_api', false),
            'owner_api_log.rules' => '',
            'close_owner_api_log_except' => 0,
        ]);
        $settings = [
            'enabled' => filter_var($values['owner_api_log.enabled'], FILTER_VALIDATE_BOOLEAN),
            'filter_mode' => config('owner_api_log.filter_mode', 'whitelist'),
            'include' => (array) config('owner_api_log.include', []),
            'exclude' => (array) (config('owner_api_log.exclude') ?? config('app.log.except', [])),
            'legacy_bypass' => filter_var($values['close_owner_api_log_except'], FILTER_VALIDATE_BOOLEAN),
        ];
        $raw = $values['owner_api_log.rules'];
        if ($raw === '') {
            return $settings;
        }

        // 数据库存在显式白名单后，只按该名单采集；[] 必须保留“不采集”的含义。
        $rules = is_string($raw) ? json_decode($raw, true) : $raw;
        $settings['filter_mode'] = 'whitelist';
        $settings['include'] = [];
        if (!is_array($rules) || !array_is_list($rules)) {
            return $settings;
        }
        foreach ($rules as $rule) {
            if (is_array($rule) && is_string($rule['path'] ?? null)
                && filter_var($rule['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $settings['include'][] = $rule['path'];
            }
        }

        return $settings;
    }

    public function retentionDays(): mixed
    {
        return app(GlobalConfigRuntimeResolver::class)->get(
            'owner_api_log.retention_days', config('owner_api_log.retention_days', 7)
        );
    }
}
