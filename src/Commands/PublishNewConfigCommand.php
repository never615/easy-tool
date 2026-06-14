<?php

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;
use Mallto\Tool\Domain\NewConfig\NewConfigPublisher;
use Throwable;

class PublishNewConfigCommand extends Command
{
    protected $signature = 'tool:new_config_publish
        {--restart : 发布后触发服务重启}
        {--reload : 兼容旧参数，等同于 --restart}
        {--force-config-cache : 即使配置缓存文件不存在，也重新生成 config cache}
        {--env-file= : 运行期 shell env 文件路径，默认 storage/framework/new_configs.env}
        {--json : 输出 JSON}';

    protected $description = 'Export runtime config snapshots and refresh Laravel config cache';

    public function handle(NewConfigPublisher $publisher): int
    {
        try {
            $result = $publisher->publish(
                (bool)$this->option('restart') || (bool)$this->option('reload'),
                (bool)$this->option('force-config-cache'),
                $this->option('env-file') ?: null
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
        $subjectConfig = $result['subject_config'] ?? [];
        $configCache = $result['config_cache'] ?? null;
        $generation = $result['generation'] ?? null;
        $restart = $result['restart'] ?? null;

        $this->info('new_configs exported: values=' . count($result['values'] ?? []));
        $this->line('runtime env changed: ' . (($write['changed'] ?? false) ? 'yes' : 'no'));
        $this->line('runtime env file: ' . ($write['env_path'] ?? 'n/a'));
        $this->line('subject_configs exported: subjects=' . (int)($subjectConfig['counts']['subjects'] ?? 0)
            . ', values=' . (int)($subjectConfig['counts']['values'] ?? 0));
        $this->line('subject config values changed: ' . (($subjectConfig['values_file']['changed'] ?? false) ? 'yes' : 'no'));
        $this->line('subject config values file: ' . ($subjectConfig['values_file']['values_path'] ?? 'n/a'));

        if (is_array($configCache)) {
            $this->line('config cache: ' . (($configCache['skipped'] ?? false) ? 'skipped' : 'refreshed'));
        }

        if (is_array($generation)) {
            $this->line('config generation: ' . (($generation['skipped'] ?? false) ? 'skipped' : ($generation['generation'] ?? 'n/a')));
        }

        if (is_array($restart)) {
            $this->line('service restart: ' . (($restart['skipped'] ?? false) ? 'skipped' : 'scheduled'));
            if (isset($restart['strategy'])) {
                $this->line('restart strategy: ' . $restart['strategy']);
            }

            $horizon = $restart['horizon'] ?? null;
            if (is_array($horizon)) {
                $this->line('horizon terminate: ' . (($horizon['skipped'] ?? false) ? 'skipped' : 'requested'));
            }
        }

        return 0;
    }
}
