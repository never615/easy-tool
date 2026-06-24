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
