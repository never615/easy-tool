<?php

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;
use Mallto\Tool\Domain\NewConfig\NewConfigPublisher;
use Throwable;

/**
 * PublishNewConfigCommand：publish new config command 对应的 Artisan 命令。
 * 命令将运维入口收敛为可重复执行的流程。
 */
class PublishNewConfigCommand extends Command
{
    protected $signature = 'tool:new_config_publish
        {--restart : 发布后触发服务重启}
        {--reload : 兼容旧参数，等同于 --restart}
        {--force-config-cache : 即使配置缓存文件不存在，也重新生成 config cache}
        {--env-file= : 运行期 shell env 文件路径，默认 storage/framework/new_configs.env}
        {--json : 输出 JSON}';

    protected $description = 'Publish generated startup config and optionally schedule service restart';

    /**
     * 处理「handle」相关的内部协作步骤。
     */
    public function handle(NewConfigPublisher $publisher): int
    {
        try {
            $restart = (bool)$this->option('restart') || (bool)$this->option('reload');
            $result = $publisher->publish(
                $restart,
                $restart || (bool)$this->option('force-config-cache'),
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
        $configCache = $result['config_cache'] ?? null;
        $generation = $result['generation'] ?? null;
        $restart = $result['restart'] ?? null;

        $this->info('启动配置已发布: values=' . count($result['values'] ?? []));
        $this->line('runtime env changed: ' . (($write['changed'] ?? false) ? 'yes' : 'no'));
        $this->line('runtime env file: ' . ($write['env_path'] ?? 'n/a'));
        if (is_array($configCache)) {
            $this->line('config cache: ' . (($configCache['skipped'] ?? false) ? 'skipped' : 'refreshed'));
        }

        if (is_array($generation)) {
            $this->line('config generation: ' . (($generation['skipped'] ?? false) ? 'skipped' : ($generation['generation'] ?? 'n/a')));
        }

        if (is_array($restart)) {
            if (($restart['reason'] ?? null) === 'new_config.restart.enabled is false') {
                $this->warn('重启功能已关闭；未递增配置世代，也未调度服务重启。');
            }
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
