<?php

namespace Mallto\Tool\Domain\NewConfig;

use Illuminate\Database\Eloquent\Builder;
use Mallto\Tool\Data\NewConfig;

final class GlobalConfigOverrideManager
{
    public function setManual(string $key, string $value): NewConfig
    {
        return $this->save($key, $value, GlobalConfigOverrideSource::MANUAL);
    }

    public function setSystem(string $key, mixed $value): NewConfig
    {
        return $this->save($key, $value, GlobalConfigOverrideSource::SYSTEM);
    }

    public function adopt(string $key): NewConfig
    {
        $row = $this->globalRow($key)->firstOrFail();
        $row->override_source = GlobalConfigOverrideSource::REVIEWED;
        $row->save();

        return $row;
    }

    public function clear(string $key): void
    {
        $row = $this->globalRow($key)->first();
        if ($row === null) {
            return;
        }

        if ($row->override_source === null && !$row->is_enabled) {
            return;
        }

        $row->override_source = null;
        $row->is_enabled = false;
        $row->save();
    }

    private function save(string $key, mixed $value, string $source): NewConfig
    {
        $key = trim($key);
        if ($key === '') {
            throw new \InvalidArgumentException('Global config key must not be empty.');
        }

        GlobalConfigOverrideSource::assertValid($source);
        $definition = GlobalConfigDefinitions::find($key);
        $attributes = $definition === null
            ? GlobalConfigNewConfig::attributesFor($key)
            : GlobalConfigNewConfig::attributesForDefinition($definition);

        $row = NewConfig::query()->firstOrNew(['key' => $key]);
        if (!$row->exists) {
            $row->fill($attributes);
        } else {
            $row->env_key = $attributes['env_key'];
            $row->group_key = NewConfig::GROUP_GLOBAL_CONFIG;
            $row->requires_reload = false;
        }

        $type = (string)($attributes['type'] ?? $row->type ?? 'string');
        $row->type = $type;
        $row->value = GlobalConfigBaselineResolver::normalize($type, $value);
        $row->override_source = $source;
        $row->is_enabled = true;
        $row->save();

        return $row;
    }

    /** @return Builder<NewConfig> */
    private function globalRow(string $key): Builder
    {
        return NewConfig::query()
            ->where('group_key', NewConfig::GROUP_GLOBAL_CONFIG)
            ->where('key', trim($key));
    }
}
