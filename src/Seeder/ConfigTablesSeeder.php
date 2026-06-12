<?php

namespace Mallto\Tool\Seeder;

use Illuminate\Database\Seeder;
use Mallto\Tool\Data\Config;
use Mallto\Tool\Data\NewConfig;
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
        $config = Config::query()->firstOrNew([
            'key' => Config::HYTERA_DMR_MOCK_LOCATOR_NON_ERROR_LOG,
        ]);

        $config->remark = '海能达模拟日志开关';
        if (!$config->exists) {
            $config->value = '1';
        }
        $config->save();

        $this->seedNewConfigDefinitions();
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
