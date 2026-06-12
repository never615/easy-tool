<?php

namespace Mallto\Tool\Domain\NewConfig;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Mallto\Tool\Data\NewConfig;

class SwooleTaskMonitorConfigForm
{
    public function __construct(private NewConfigPublisher $publisher)
    {
    }

    public static function definitions(): array
    {
        return [
            NewConfig::KEY_SWOOLE_TASK_MONITOR_ENABLED => [
                'key' => NewConfig::KEY_SWOOLE_TASK_MONITOR_ENABLED,
                'env_key' => 'SWOOLE_TASK_MONITOR_ENABLED',
                'group_key' => NewConfig::GROUP_SWOOLE_TASK_MONITOR,
                'name' => 'Swoole Task 监控总开关',
                'type' => 'boolean',
                'default_value' => '0',
                'options' => '0,1',
                'remark' => '默认关闭。保存后发布运行期 env 并刷新 config cache，手动重启服务后生效。',
                'requires_reload' => true,
                'sort' => 100,
            ],
            NewConfig::KEY_SWOOLE_TASK_MONITOR_MODE => [
                'key' => NewConfig::KEY_SWOOLE_TASK_MONITOR_MODE,
                'env_key' => 'SWOOLE_TASK_MONITOR_MODE',
                'group_key' => NewConfig::GROUP_SWOOLE_TASK_MONITOR,
                'name' => 'Swoole Task 监控模式',
                'type' => 'select',
                'default_value' => 'summary',
                'options' => 'summary,trace,off',
                'remark' => 'summary 只记录聚合指标；trace 记录逐条等待和运行中明细；off 关闭。手动重启服务后生效。',
                'requires_reload' => true,
                'sort' => 110,
            ],
            NewConfig::KEY_SWOOLE_TASK_MONITOR_TRACE_SAMPLE_RATE => [
                'key' => NewConfig::KEY_SWOOLE_TASK_MONITOR_TRACE_SAMPLE_RATE,
                'env_key' => 'SWOOLE_TASK_MONITOR_TRACE_SAMPLE_RATE',
                'group_key' => NewConfig::GROUP_SWOOLE_TASK_MONITOR,
                'name' => 'Swoole Task trace 采样率',
                'type' => 'float',
                'default_value' => '0',
                'options' => '0,0.001,0.01,0.1,1',
                'remark' => '取值 0 到 1。summary 模式下可临时设置 0.01 追踪约 1% task。手动重启服务后生效。',
                'requires_reload' => true,
                'sort' => 120,
            ],
        ];
    }

    public static function modeOptions(): array
    {
        return [
            'summary' => 'summary',
            'trace' => 'trace',
            'off' => 'off',
        ];
    }

    public function snapshot(): array
    {
        $definitions = self::definitions();
        $rows = NewConfig::query()
            ->whereIn('key', array_keys($definitions))
            ->get()
            ->keyBy('key');

        $enabledValue = $this->effectiveValue(
            $rows->get(NewConfig::KEY_SWOOLE_TASK_MONITOR_ENABLED),
            $definitions[NewConfig::KEY_SWOOLE_TASK_MONITOR_ENABLED]
        );
        $mode = $this->effectiveValue(
            $rows->get(NewConfig::KEY_SWOOLE_TASK_MONITOR_MODE),
            $definitions[NewConfig::KEY_SWOOLE_TASK_MONITOR_MODE]
        );
        $sampleRate = $this->effectiveValue(
            $rows->get(NewConfig::KEY_SWOOLE_TASK_MONITOR_TRACE_SAMPLE_RATE),
            $definitions[NewConfig::KEY_SWOOLE_TASK_MONITOR_TRACE_SAMPLE_RATE]
        );

        if (!array_key_exists($mode, self::modeOptions())) {
            $mode = (string)$definitions[NewConfig::KEY_SWOOLE_TASK_MONITOR_MODE]['default_value'];
        }

        return [
            'enabled' => filter_var($enabledValue, FILTER_VALIDATE_BOOLEAN),
            'enabled_value' => filter_var($enabledValue, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'mode' => $mode,
            'trace_sample_rate' => $this->normalizeSampleRate($sampleRate),
            'rows' => collect($definitions)
                ->map(function (array $definition, string $key) use ($rows) {
                    return $this->rowSnapshot($key, $definition, $rows->get($key));
                })
                ->values()
                ->all(),
        ];
    }

    public function save(array $input): array
    {
        $validated = Validator::make($input, [
            'enabled' => [ 'required', 'boolean' ],
            'mode' => [ 'required', Rule::in(array_keys(self::modeOptions())) ],
            'trace_sample_rate' => [ 'required', 'numeric', 'min:0', 'max:1' ],
        ], [
            'enabled.required' => '请选择是否开启监控。',
            'enabled.boolean' => '监控开关只能是开启或关闭。',
            'mode.required' => '请选择监控模式。',
            'mode.in' => '监控模式只能选择 summary、trace 或 off。',
            'trace_sample_rate.required' => '请填写 trace 采样率。',
            'trace_sample_rate.numeric' => 'trace 采样率必须是数字。',
            'trace_sample_rate.min' => 'trace 采样率不能小于 0。',
            'trace_sample_rate.max' => 'trace 采样率不能大于 1。',
        ])->validate();

        $values = [
            NewConfig::KEY_SWOOLE_TASK_MONITOR_ENABLED => filter_var($validated['enabled'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            NewConfig::KEY_SWOOLE_TASK_MONITOR_MODE => (string)$validated['mode'],
            NewConfig::KEY_SWOOLE_TASK_MONITOR_TRACE_SAMPLE_RATE => $this->normalizeSampleRate($validated['trace_sample_rate']),
        ];

        NewConfig::withoutAutoPublish(function () use ($values) {
            foreach ($values as $key => $value) {
                $this->saveValue($key, $value);
            }
        });

        $this->publisher->publish(false);

        return $this->snapshot();
    }

    private function saveValue(string $key, string $value): void
    {
        $definitions = self::definitions();
        $config = NewConfig::query()->firstOrNew([
            'key' => $key,
        ]);

        if (!$config->exists) {
            $config->fill($definitions[$key]);
        }

        $config->value = $value;
        $config->is_enabled = true;
        $config->save();
    }

    private function effectiveValue(?NewConfig $row, array $definition): string
    {
        if ($row === null) {
            return (string)($definition['default_value'] ?? '');
        }

        $value = $row->is_enabled ? $row->value : $row->default_value;
        if ($value === null || $value === '') {
            $value = $row->default_value;
        }
        if ($value === null || $value === '') {
            $value = $definition['default_value'] ?? '';
        }

        return (string)$value;
    }

    private function rowSnapshot(string $key, array $definition, ?NewConfig $row): array
    {
        $lastPublishedAt = $row && $row->last_published_at
            ? $row->last_published_at->format('Y-m-d H:i:s')
            : null;

        return [
            'key' => $key,
            'env_key' => $row && $row->env_key ? $row->env_key : $definition['env_key'],
            'name' => $row && $row->name ? $row->name : $definition['name'],
            'value' => $this->effectiveValue($row, $definition),
            'row_value' => $row ? $row->value : null,
            'default_value' => $row ? $row->default_value : $definition['default_value'],
            'is_enabled' => $row ? (bool)$row->is_enabled : true,
            'requires_reload' => $row ? (bool)$row->requires_reload : (bool)$definition['requires_reload'],
            'last_published_at' => $lastPublishedAt,
            'last_publish_error' => $row ? $row->last_publish_error : null,
        ];
    }

    private function normalizeSampleRate($value): string
    {
        $normalized = trim((string)$value);

        return $normalized === '' ? '0' : $normalized;
    }
}
