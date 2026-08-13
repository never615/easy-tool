<?php

namespace Mallto\Tool\Domain\NewConfig;

use InvalidArgumentException;
use LogicException;
use Mallto\Tool\Data\NewConfig;

/**
 * LocationIngressDiagnosticsModeSwitch：仅管理定位上报诊断模式对应的固定 Env 覆盖。
 */
final class LocationIngressDiagnosticsModeSwitch
{
    public const ENV_KEY = 'LOCATION_INGEST_DIAGNOSTICS_MODE';

    public const MODES = ['off', 'summary', 'trace'];

    /**
     * 注入共享人工发布服务，避免本开关绕过全局发布节流与重启边界。
     */
    public function __construct(private NewConfigManualPublishService $manualPublisher)
    {
    }

    /**
     * 返回配置中心中已请求的目标模式；不读取 Redis 或当前进程配置。
     *
     * @return array{configured: bool, requested_mode: string|null}
     */
    public function status(): array
    {
        $row = $this->findRuntimeOverride();
        if ($row === null) {
            return [
                'configured' => false,
                'requested_mode' => null,
            ];
        }

        return [
            'configured' => true,
            'requested_mode' => $this->publishedModeValue($row),
        ];
    }

    /**
     * 固定写入诊断模式并发布启动配置。off 仍保留启用的覆盖行和值，避免回退到真实 .env。
     *
     * @return array{configured: bool, requested_mode: string|null, publish: array|null}
     */
    public function set(string $mode): array
    {
        $this->assertAllowedMode($mode);

        $attributes = RuntimeEnvOverrideConfig::attributesFor(
            self::ENV_KEY,
            '定位上报诊断模式',
            'string',
            $mode,
            'off',
            '仅诊断期按需启用；发布启动配置并重启新进程后生效。',
            true
        );
        $attributes['options'] = implode(',', self::MODES);

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
            'requested_mode' => $mode,
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
            throw new LogicException('location_ingress_diagnostics_mode_env_key_conflict');
        }

        return $rows->first();
    }

    /**
     * 解析配置行这次将发布的模式，并拒绝绕过固定白名单的历史记录。
     */
    private function publishedModeValue(NewConfig $row): string
    {
        $mode = $row->is_enabled ? $row->value : $row->default_value;
        if ($mode === null || $mode === '') {
            $mode = $row->default_value;
        }

        $mode = (string)$mode;
        if (!in_array($mode, self::MODES, true)) {
            throw new LogicException('location_ingress_diagnostics_mode_value_invalid');
        }

        return $mode;
    }

    /**
     * 确保浏览器及其他调用方只能提交固定的三档诊断模式。
     */
    private function assertAllowedMode(string $mode): void
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('location_ingress_diagnostics_mode_invalid');
        }
    }
}
