<?php

namespace Mallto\Tool\Domain\NewConfig;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Mallto\Tool\Data\NewConfig;
use Mallto\Tool\Exception\ResourceException;

class GlobalConfigModuleForm
{
    public function __construct(private NewConfigPublisher $publisher)
    {
    }

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

    public function save(string $module, array $input): array
    {
        $this->moduleMeta($module);
        $definitions = GlobalConfigDefinitions::definitions($module);
        $values = $input['values'] ?? [];
        $validated = [
            'values' => [],
        ];

        foreach ($definitions as $key => $definition) {
            $validated['values'][$key] = $this->validateValue($key, $definition, $values[$key] ?? null);
        }

        NewConfig::withoutAutoPublish(function () use ($definitions, $validated) {
            foreach ($definitions as $key => $definition) {
                $value = $validated['values'][$key] ?? '';
                $this->saveValue($key, $definition, $this->normalizeValue($definition, $value));
            }
        });

        $this->publisher->publish(false);

        return $this->snapshot($module);
    }

    private function saveValue(string $key, array $definition, string $value): void
    {
        $config = NewConfig::query()->firstOrNew([
            'key' => $key,
        ]);
        $attributes = GlobalConfigNewConfig::attributesForDefinition($definition, $value);

        $config->fill($attributes);
        $config->value = $value;
        $config->is_enabled = true;
        $config->save();
    }

    private function rowSnapshot(string $key, array $definition, ?NewConfig $row): array
    {
        $value = $row ? $row->value : null;
        if ($value === null || $value === '') {
            $value = $definition['default_value'] ?? '';
        }

        return [
            'key' => $key,
            'env_key' => $row && $row->env_key ? $row->env_key : GlobalConfigNewConfig::envKeyFor($key),
            'name' => (string)($definition['name'] ?? ($row->name ?? $key)),
            'value' => (string)$value,
            'type' => (string)($definition['type'] ?? 'string'),
            'ui' => (string)($definition['ui'] ?? 'input'),
            'default_value' => (string)($definition['default_value'] ?? ''),
            'remark' => (string)($definition['remark'] ?? ($row->remark ?? '')),
            'last_published_at' => $row && $row->last_published_at
                ? $row->last_published_at->format('Y-m-d H:i:s')
                : null,
            'last_publish_error' => $row ? $row->last_publish_error : null,
        ];
    }

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

    private function normalizeValue(array $definition, $value): string
    {
        return match ((string)($definition['type'] ?? 'string')) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'integer' => (string)((int)$value),
            'float' => (string)((float)$value),
            default => trim((string)$value),
        };
    }

    private function moduleMeta(string $module): array
    {
        $meta = GlobalConfigDefinitions::module($module);
        if ($meta === null) {
            throw new ResourceException('未知配置模块：' . $module);
        }

        return $meta;
    }
}
