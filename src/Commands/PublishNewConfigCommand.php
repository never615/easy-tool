<?php

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;
use Mallto\Tool\Domain\NewConfig\NewConfigPublisher;
use Throwable;

class PublishNewConfigCommand extends Command
{
    protected $signature = 'tool:new_config_publish
        {--reload : 发布后执行 LaravelS reload}
        {--force-config-cache : 即使配置缓存文件不存在，也重新生成 config cache}
        {--json : 输出 JSON}';

    protected $description = 'Publish new_configs rows into .env and refresh Laravel config cache';

    public function handle(NewConfigPublisher $publisher): int
    {
        try {
            $result = $publisher->publish(
                (bool)$this->option('reload'),
                (bool)$this->option('force-config-cache')
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return 1;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $write = $result['write'] ?? [];
        $configCache = $result['config_cache'] ?? null;
        $reload = $result['reload'] ?? null;

        $this->info('new_configs published: values=' . count($result['values'] ?? []));
        $this->line('env changed: ' . (($write['changed'] ?? false) ? 'yes' : 'no'));

        if (isset($write['backup_path']) && $write['backup_path']) {
            $this->line('env backup: ' . $write['backup_path']);
        }

        if (is_array($configCache)) {
            $this->line('config cache: ' . (($configCache['skipped'] ?? false) ? 'skipped' : 'refreshed'));
        }

        if (is_array($reload)) {
            $this->line('laravels reload: ' . (($reload['skipped'] ?? false) ? 'skipped' : 'done'));
        }

        return 0;
    }
}
