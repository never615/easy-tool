<?php

namespace Mallto\Tool\Domain\NewConfig;

use Mallto\Tool\Data\NewConfig;

/**
 * 将已固化的全局配置 Definitions 同步到 new_configs。
 *
 * Definitions 的 key 来自开发阶段对 ConfigUtils::get() 使用点的 AI 辅助源码盘点。
 * Syncer 本身不扫描源码、不自动发现 key；它只消费调用方传入的 Definitions 数组。
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
                    $config->value = null;
                    $config->override_source = null;
                    $config->is_enabled = false;
                } else {
                    unset($attributes['value'], $attributes['is_enabled'], $attributes['override_source']);
                    $config->fill($attributes);
                }

                if (!$config->exists || $config->isDirty()) {
                    $config->save();
                }
            }
        });
    }
}
