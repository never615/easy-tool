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
        Config::query()->firstOrCreate([
            'key' => Config::HYTERA_DMR_MOCK_LOCATOR_NON_ERROR_LOG,
        ], [
            'remark' => 'Hytera DMR 模拟定位进程非 error 日志开关，1开启，0关闭',
            'value' => '1',
        ]);
    }
}
