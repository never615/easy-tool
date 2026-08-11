<?php

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Mallto\Tool\Data\NewConfig;
use Mallto\Tool\Domain\NewConfig\GlobalConfigOverrideManager;
use Mallto\Tool\Domain\NewConfig\GlobalConfigOverrideSource;

/**
 * 将经人工核对的历史全局配置标记为显式覆盖。
 */
class AdoptGlobalConfigOverridesCommand extends Command
{
    protected $signature = 'tool:global_config_override_adopt
        {keys* : 已确认需要继续覆盖 config 基线的完整 key}
        {--source=reviewed : 覆盖来源，审核接纳只允许 reviewed}';

    protected $description = 'Adopt reviewed legacy global config rows as explicit overrides';

    public function handle(GlobalConfigOverrideManager $manager): int
    {
        $keys = array_map('strval', (array)$this->argument('keys'));
        $source = (string)$this->option('source');

        if ($source !== GlobalConfigOverrideSource::REVIEWED) {
            $this->error('审核接纳命令只允许 --source=reviewed。');

            return self::FAILURE;
        }

        if (count($keys) !== count(array_unique($keys))) {
            $this->error('不得重复传入同一个 key。');

            return self::FAILURE;
        }

        $rows = NewConfig::query()->whereIn('key', $keys)->get()->keyBy('key');
        foreach ($keys as $key) {
            $row = $rows->get($key);
            if ($row === null) {
                $this->error("配置不存在：{$key}");

                return self::FAILURE;
            }
            if ($row->group_key !== NewConfig::GROUP_GLOBAL_CONFIG) {
                $this->error("配置不属于 global_config：{$key}");

                return self::FAILURE;
            }
        }

        DB::transaction(function () use ($keys, $manager): void {
            foreach ($keys as $key) {
                $manager->adopt($key);
            }
        });

        foreach ($keys as $key) {
            $this->info("{$key}\t" . GlobalConfigOverrideSource::REVIEWED . "\tadopted");
        }

        return self::SUCCESS;
    }
}
