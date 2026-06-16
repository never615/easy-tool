<?php

namespace Mallto\Tool\Domain\NewConfig;

use Mallto\Tool\Data\NewConfig;

class GlobalConfigDefinitionSyncer
{
    public function sync(array $definitions): void
    {
        NewConfig::withoutAutoPublish(function () use ($definitions) {
            foreach ($definitions as $definition) {
                $key = (string)($definition['key'] ?? '');
                if ($key === '') {
                    continue;
                }

                $config = NewConfig::query()
                    ->where('key', $key)
                    ->first();

                if ($config !== null) {
                    $value = $config->value;
                    $isEnabled = $config->is_enabled;
                    $config->fill(GlobalConfigNewConfig::attributesForDefinition($definition, $value));
                    $config->value = $value;
                    $config->is_enabled = $isEnabled;
                    $config->save();
                    continue;
                }

                $config = new NewConfig([
                    'key' => $key,
                ]);

                $config->fill(GlobalConfigNewConfig::attributesForDefinition($definition));
                $config->save();
            }
        });
    }
}
