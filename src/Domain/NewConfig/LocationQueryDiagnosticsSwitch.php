<?php

namespace Mallto\Tool\Domain\NewConfig;

use LogicException;
use Mallto\Tool\Data\NewConfig;

/**
 * LocationQueryDiagnosticsSwitch：仅管理实时地图查询诊断对应的固定 Env 覆盖。
 *
 * 查询诊断是 LaravelS 启动配置。所有写入必须经过人工发布器，以便沿用
 * 配置缓存刷新、重启边界和 30 秒人工发布节流。
 */
final class LocationQueryDiagnosticsSwitch
{
    public const ENV_KEY = 'LOCATION_QUERY_DIAGNOSTICS_ENABLED';

    public function __construct(private NewConfigManualPublishService $manualPublisher)
    {
    }

    /**
     * 返回配置中心中保存的目标状态，不读取当前进程配置。
     *
     * @return array{configured: bool, requested_enabled: bool|null}
     */
    public function status(): array
    {
        $row = $this->findRuntimeOverride();
        if ($row === null) {
            return ['configured' => false, 'requested_enabled' => null];
        }

        return [
            'configured' => true,
            'requested_enabled' => $this->publishedEnabledValue($row),
        ];
    }

    /**
     * 固定写入查询诊断开关并发布启动配置。
     *
     * @return array{configured: bool, requested_enabled: bool|null, publish: array|null}
     */
    public function set(bool $enabled): array
    {
        $attributes = RuntimeEnvOverrideConfig::attributesFor(
            self::ENV_KEY,
            '实时地图查询诊断开关',
            'boolean',
            $enabled ? '1' : '0',
            '0',
            '仅诊断期按需启用；发布启动配置并重启 LaravelS 后生效。',
            true
        );

        $publish = $this->manualPublisher->publish(function () use ($attributes): void {
            $row = $this->findRuntimeOverride();

            NewConfig::withoutAutoPublish(function () use ($attributes, $row): void {
                $config = $row ?? new NewConfig();
                $config->fill($attributes);
                $config->save();
            });
        });

        if ($publish === null) {
            return $this->status() + ['publish' => null];
        }

        return [
            'configured' => true,
            'requested_enabled' => $enabled,
            'publish' => $publish,
        ];
    }

    /** @throws LogicException when duplicate or mis-grouped rows exist. */
    private function findRuntimeOverride(): ?NewConfig
    {
        $rows = NewConfig::query()->where('env_key', self::ENV_KEY)->get();
        if ($rows->count() === 0) {
            return null;
        }
        if ($rows->count() !== 1 || $rows->first()->group_key !== NewConfig::GROUP_RUNTIME_ENV_OVERRIDE) {
            throw new LogicException('location_query_diagnostics_env_key_conflict');
        }

        return $rows->first();
    }

    private function publishedEnabledValue(NewConfig $row): bool
    {
        $value = $row->is_enabled ? $row->value : $row->default_value;
        if ($value === null || $value === '') {
            $value = $row->default_value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
