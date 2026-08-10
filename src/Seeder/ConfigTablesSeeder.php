<?php

namespace Mallto\Tool\Seeder;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Mallto\Tool\Data\Config;
use Mallto\Tool\Data\NewConfig;
use Mallto\Tool\Domain\NewConfig\GlobalConfigDefinitions;
use Mallto\Tool\Domain\NewConfig\GlobalConfigNewConfig;
use Mallto\Tool\Domain\NewConfig\GlobalConfigDefinitionSyncer;
use Mallto\Tool\Domain\NewConfig\SwooleTaskMonitorConfigForm;

/**
 * ConfigTablesSeeder：初始化新配置中心并迁移旧全局配置。
 *
 * `configs` 已是历史表：迁移只会把旧表中缺失的 key 补入 `new_configs`，
 * 不会覆盖已有 NewConfig，也不会把新值回写旧表。运行期读取与写入均不应依赖 `configs`。
 */
class ConfigTablesSeeder extends Seeder
{

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->migrateLegacyConfigs();
        $this->seedGlobalConfigDefinitions();
        $this->seedNewConfigDefinitions();
    }

    /**
     * 单向读取旧 `configs`，为缺失 key 创建 `new_configs.global_config` 记录。
     *
     * 保留旧表是为了升级兼容和可追溯，不表示它仍是运行期配置来源。
     */
    private function migrateLegacyConfigs(): void
    {
        if (!Schema::hasTable('configs')) {
            return;
        }

        NewConfig::withoutAutoPublish(function () {
            Config::query()
                ->orderBy('id')
                ->chunkById(100, function ($configs) {
                    foreach ($configs as $config) {
                        $this->seedGlobalConfig(
                            (string)$config->key,
                            $config->value === null ? null : (string)$config->value,
                            $config->remark === null ? null : (string)$config->remark
                        );
                    }
                });
        });
    }

    /**
     * 处理「seedGlobalConfig」相关的内部协作步骤。
     */
    private function seedGlobalConfig(string $key, ?string $value, ?string $remark = null): void
    {
        $exists = NewConfig::query()
            ->where('key', $key)
            ->exists();

        if ($exists) {
            return;
        }

        $config = new NewConfig([
            'key' => $key,
        ]);

        $config->fill(GlobalConfigNewConfig::attributesFor($key, $value, $remark));
        $config->save();
    }

    /**
     * 处理「seedGlobalConfigDefinitions」相关的内部协作步骤。
     */
    private function seedGlobalConfigDefinitions(): void
    {
        app(GlobalConfigDefinitionSyncer::class)->sync(GlobalConfigDefinitions::definitions());
    }

    /**
     * 处理「seedNewConfigDefinitions」相关的内部协作步骤。
     */
    private function seedNewConfigDefinitions(): void
    {
        $definitions = SwooleTaskMonitorConfigForm::definitions();

        NewConfig::withoutAutoPublish(function () use ($definitions) {
            foreach ($definitions as $definition) {
                $config = NewConfig::query()
                    ->where('key', $definition['key'])
                    ->first();

                if ($config !== null) {
                    if (SwooleTaskMonitorConfigForm::backfillMissingDefinitionAttributes($config, $definition)) {
                        $config->save();
                    }

                    continue;
                }

                $config = new NewConfig([
                    'key' => $definition['key'],
                ]);

                $config->fill($definition);
                $config->value = $definition['default_value'];
                $config->is_enabled = true;
                $config->save();
            }
        });
    }
}
