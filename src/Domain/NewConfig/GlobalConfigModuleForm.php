<?php

namespace Mallto\Tool\Domain\NewConfig;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Mallto\Tool\Data\NewConfig;
use Mallto\Tool\Exception\ResourceException;

/**
 * GlobalConfigModuleForm：配置中心发布与运行时生效链路的一环。
 * 它区分持久化配置、生成文件、配置缓存与长驻进程重载，避免调用方绕过发布边界。
 */
class GlobalConfigModuleForm
{
    /**
     * 注入本组件运行所需的协作依赖，保持构造与业务执行解耦。
     */
    public function __construct(
        private NewConfigPublisher $publisher,
        private ?GlobalConfigOverrideManager $overrideManager = null,
        private ?GlobalConfigBaselineResolver $baselineResolver = null
    )
    {
    }

    /**
     * 处理「snapshot」相关的内部协作步骤。
     */
    public function snapshot(string $module): array
    {
        $moduleMeta = $this->moduleMeta($module);
        $definitions = GlobalConfigDefinitions::definitions($module);
        $rows = NewConfig::query()
            ->whereIn('key', array_keys($definitions))
            ->get()
            ->keyBy('key');

        return [
            'module' => $module,
            'title' => $moduleMeta['title'],
            'description' => $moduleMeta['description'] ?? '',
            'route' => $moduleMeta['route'],
            'save_route' => $moduleMeta['save_route'],
            'rows' => collect($definitions)
                ->map(function (array $definition, string $key) use ($rows) {
                    return $this->rowSnapshot($key, $definition, $rows->get($key));
                })
                ->values()
                ->all(),
        ];
    }

    /**
     * 处理「save」相关的内部协作步骤。
     */
    public function save(string $module, array $input): array
    {
        $this->moduleMeta($module);
        $definitions = GlobalConfigDefinitions::definitions($module);
        $overrides = Validator::make($input, [
            'overrides' => ['required', 'array'],
        ], [
            'overrides.required' => '缺少数据库覆盖表单数据。',
            'overrides.array' => '数据库覆盖表单格式无效。',
        ])->validate()['overrides'];

        foreach ($definitions as $key => $definition) {
            if (($definition['override_policy'] ?? null) === 'system') {
                continue;
            }

            if (!array_key_exists($key, $overrides)) {
                continue;
            }

            $override = is_array($overrides[$key]) ? $overrides[$key] : [];
            $enabled = filter_var($override['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (!$enabled) {
                $this->manager()->clear($key);

                continue;
            }

            $value = $this->validateValue($key, $definition, $override['value'] ?? null);
            $this->manager()->setManual($key, $this->normalizeValue($definition, $value));
        }

        return $this->snapshot($module);
    }

    /**
     * 处理「rowSnapshot」相关的内部协作步骤。
     */
    private function rowSnapshot(string $key, array $definition, ?NewConfig $row): array
    {
        $baseline = $this->baseline()->forDefinition($definition);
        $activeOverride = $row !== null
            && $row->override_source !== null
            && (bool)$row->is_enabled;
        $sensitive = ($definition['override_policy'] ?? null) === 'system'
            || $key === 'app_secret';
        $displayBaseline = $sensitive ? null : $baseline;
        $displayOverride = $sensitive ? null : $row?->value;
        $displayEffective = $sensitive ? null : ($activeOverride ? $row?->value : $baseline);

        return [
            'key' => $key,
            'env_key' => $row && $row->env_key ? $row->env_key : GlobalConfigNewConfig::envKeyForDefinition($definition),
            'name' => (string)($definition['name'] ?? ($row->name ?? $key)),
            'value' => $displayEffective === null ? '' : (string)$displayEffective,
            'baseline_value' => $displayBaseline === null ? null : (string)$displayBaseline,
            'override_value' => $displayOverride === null ? null : (string)$displayOverride,
            'effective_value' => $displayEffective === null ? null : (string)$displayEffective,
            'effective_source' => $activeOverride
                ? 'database_override'
                : (string)($definition['baseline_source'] ?? 'code_default'),
            'override_source' => $row?->override_source,
            'override_enabled' => $activeOverride,
            'override_policy' => (string)($definition['override_policy'] ?? 'manual'),
            'sensitive' => $sensitive,
            'type' => (string)($definition['type'] ?? 'string'),
            'ui' => (string)($definition['ui'] ?? 'input'),
            'placeholder' => (string)($definition['placeholder'] ?? ''),
            'family_key' => (string)($definition['family_key'] ?? ''),
            'family_label' => (string)($definition['family_label'] ?? ''),
            'default_value' => (string)($definition['default_value'] ?? ''),
            'remark' => (string)($definition['remark'] ?? ($row->remark ?? '')),
            'requires_reload' => false,
            'last_published_at' => $row && $row->last_published_at
                ? $row->last_published_at->format('Y-m-d H:i:s')
                : null,
            'last_publish_error' => $row ? $row->last_publish_error : null,
        ];
    }

    /**
     * 处理「rulesFor」相关的内部协作步骤。
     */
    private function rulesFor(array $definition): array
    {
        return match ((string)($definition['type'] ?? 'string')) {
            'boolean' => [ 'required', Rule::in([ '0', '1', 0, 1, true, false ]) ],
            'integer' => [ 'required', 'integer' ],
            'float' => [ 'required', 'numeric' ],
            'json' => [ 'nullable', 'json' ],
            default => [ 'nullable', 'string' ],
        };
    }

    /**
     * 处理「validateValue」相关的内部协作步骤。
     */
    private function validateValue(string $key, array $definition, $value)
    {
        $name = (string)($definition['name'] ?? $key);

        $data = [
            'value' => $value,
        ];
        $rules = [
            'value' => $this->rulesFor($definition),
        ];
        $messages = [
            'value.required' => $name . '不能为空。',
            'value.in' => $name . '只能选择开启或关闭。',
            'value.integer' => $name . '必须是整数。',
            'value.numeric' => $name . '必须是数字。',
            'value.json' => $name . '必须是合法 JSON。',
        ];

        return Validator::make($data, $rules, $messages)->validate()['value'] ?? '';
    }

    /**
     * 处理「normalizeValue」相关的内部协作步骤。
     */
    private function normalizeValue(array $definition, $value): string
    {
        return match ((string)($definition['type'] ?? 'string')) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'integer' => (string)((int)$value),
            'float' => (string)((float)$value),
            default => trim((string)$value),
        };
    }

    /**
     * 处理「moduleMeta」相关的内部协作步骤。
     */
    private function moduleMeta(string $module): array
    {
        $meta = GlobalConfigDefinitions::module($module);
        if ($meta === null) {
            throw new ResourceException('未知配置模块：' . $module);
        }

        return $meta;
    }

    private function manager(): GlobalConfigOverrideManager
    {
        return $this->overrideManager ??= app(GlobalConfigOverrideManager::class);
    }

    private function baseline(): GlobalConfigBaselineResolver
    {
        return $this->baselineResolver ??= app(GlobalConfigBaselineResolver::class);
    }
}
