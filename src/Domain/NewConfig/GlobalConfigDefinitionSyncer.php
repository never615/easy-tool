<?php

namespace Mallto\Tool\Domain\NewConfig;

use Mallto\Tool\Data\NewConfig;

/**
 * GlobalConfigDefinitionSyncer：配置中心发布与运行时生效链路的一环。
 * 它区分持久化配置、生成文件、配置缓存与长驻进程重载，避免调用方绕过发布边界。
 */
class GlobalConfigDefinitionSyncer
{
    /**
     * 处理「sync」相关的内部协作步骤。
     */
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

                $attributes = GlobalConfigNewConfig::attributesForDefinition($definition);
                if ($config === null) {
                    $config = new NewConfig(['key' => $key]);
                    $config->fill($attributes);
                } else {
                    unset($attributes['value'], $attributes['is_enabled']);
                    $config->fill($attributes);
                }

                $config->save();
            }
        });
    }
}
