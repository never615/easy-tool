<?php

namespace Mallto\Tool\Domain\NewConfig;

use LogicException;
use Mallto\Tool\Data\NewConfig;

/**
 * LocationIngressCounterSwitch：仅管理定位入口调试计数对应的固定 Env 覆盖。
 */
final class LocationIngressCounterSwitch
{
    public const ENV_KEY = 'LOCATION_INGRESS_COUNTER_ENABLED';

    /**
     * 注入共享人工发布服务，避免本开关绕过全局发布节流与重启边界。
     */
    public function __construct(private NewConfigManualPublishService $manualPublisher)
    {
    }

    /**
     * 返回配置中心中已请求的目标状态；不读取 Redis 或当前进程配置。
     *
     * @return array{configured: bool, requested_enabled: bool|null}
     */
    public function status(): array
    {
        $row = $this->findRuntimeOverride();
        if ($row === null) {
            return [
                'configured' => false,
                'requested_enabled' => null,
            ];
        }

        return [
            'configured' => true,
            'requested_enabled' => $this->publishedEnabledValue($row),
        ];
    }

    /**
     * 固定写入入口计数开关并发布启动配置。关闭时仍保留启用的覆盖行和值 0，避免回退到真实 .env。
     *
     * @return array{configured: bool, requested_enabled: bool|null, publish: array|null}
     */
    public function set(bool $enabled): array
    {
        $attributes = RuntimeEnvOverrideConfig::attributesFor(
            self::ENV_KEY,
            '定位入口调试计数开关',
            'boolean',
            $enabled ? '1' : '0',
            '0',
            '仅调试期启用；发布启动配置并重启新进程后生效。',
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

    /**
     * 查找该固定 Env Key 的唯一覆盖记录，并防止错误分组的同名记录被静默覆盖。
     */
    private function findRuntimeOverride(): ?NewConfig
    {
        $rows = NewConfig::query()->where('env_key', self::ENV_KEY)->get();
        if ($rows->count() === 0) {
            return null;
        }

        if ($rows->count() !== 1 || $rows->first()->group_key !== NewConfig::GROUP_RUNTIME_ENV_OVERRIDE) {
            throw new LogicException('location_ingress_counter_env_key_conflict');
        }

        return $rows->first();
    }

    /**
     * 按发布器的回退语义计算当前覆盖行将请求的布尔值。
     */
    private function publishedEnabledValue(NewConfig $row): bool
    {
        $value = $row->is_enabled ? $row->value : $row->default_value;
        if ($value === null || $value === '') {
            $value = $row->default_value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
