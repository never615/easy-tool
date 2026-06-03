<?php

namespace Mallto\Tool\Seeder;

use Illuminate\Database\Seeder;
use Mallto\Tool\Data\Config;

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
    }
}
