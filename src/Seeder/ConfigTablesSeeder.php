<?php

namespace Mallto\Tool\Seeder;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Mallto\Tool\Data\Config;
use Mallto\Tool\Data\NewConfig;
use Mallto\Tool\Domain\NewConfig\GlobalConfigDefinitions;
use Mallto\Tool\Domain\NewConfig\GlobalConfigNewConfig;
use Mallto\Tool\Domain\NewConfig\SwooleTaskMonitorConfigForm;

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
        NewConfig::withoutAutoPublish(function () {
            $this->seedGlobalConfig(Config::HYTERA_DMR_MOCK_LOCATOR_NON_ERROR_LOG, '1', '海能达模拟日志开关');
        });
        $this->seedGlobalConfigDefinitions();
        $this->seedNewConfigDefinitions();
    }

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

    private function seedGlobalConfigDefinitions(): void
    {
        $definitions = GlobalConfigDefinitions::definitions();

        NewConfig::withoutAutoPublish(function () use ($definitions) {
            foreach ($definitions as $definition) {
                $exists = NewConfig::query()
                    ->where('key', $definition['key'])
                    ->exists();

                if ($exists) {
                    continue;
                }

                $config = new NewConfig([
                    'key' => $definition['key'],
                ]);

                $config->fill(GlobalConfigNewConfig::attributesForDefinition($definition));
                $config->save();
            }
        });
    }

    private function seedNewConfigDefinitions(): void
    {
        $definitions = SwooleTaskMonitorConfigForm::definitions();

        NewConfig::withoutAutoPublish(function () use ($definitions) {
            foreach ($definitions as $definition) {
                $exists = NewConfig::query()
                    ->where('key', $definition['key'])
                    ->exists();

                if ($exists) {
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
