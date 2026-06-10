<?php

namespace Mallto\Tool\Seeder;

use Illuminate\Database\Seeder;
use Mallto\Tool\Data\Config;
use Mallto\Tool\Data\NewConfig;

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
        $definitions = [
            [
                'key' => NewConfig::KEY_SWOOLE_TASK_MONITOR_ENABLED,
                'group_key' => NewConfig::GROUP_SWOOLE_TASK_MONITOR,
                'name' => 'Swoole Task 监控总开关',
                'type' => 'boolean',
                'default_value' => '0',
                'options' => '0,1',
                'remark' => '默认关闭。开启后 Swoole task 监控开始写入运行时聚合指标。',
                'sort' => 100,
            ],
            [
                'key' => NewConfig::KEY_SWOOLE_TASK_MONITOR_MODE,
                'group_key' => NewConfig::GROUP_SWOOLE_TASK_MONITOR,
                'name' => 'Swoole Task 监控模式',
                'type' => 'select',
                'default_value' => 'summary',
                'options' => 'summary,trace,off',
                'remark' => 'summary 只记录聚合指标；trace 记录逐条等待和运行中明细；off 关闭。',
                'sort' => 110,
            ],
            [
                'key' => NewConfig::KEY_SWOOLE_TASK_MONITOR_TRACE_SAMPLE_RATE,
                'group_key' => NewConfig::GROUP_SWOOLE_TASK_MONITOR,
                'name' => 'Swoole Task trace 采样率',
                'type' => 'float',
                'default_value' => '0',
                'options' => '0,0.001,0.01,0.1,1',
                'remark' => '取值 0 到 1。summary 模式下可临时设置 0.01 追踪约 1% task。',
                'sort' => 120,
            ],
        ];

        foreach ($definitions as $definition) {
            $config = NewConfig::query()->firstOrNew([
                'key' => $definition['key'],
            ]);

            $config->fill($definition);
            if (!$config->exists) {
                $config->value = $definition['default_value'];
            }

            $config->is_enabled = true;
            $config->save();
        }
    }
}
