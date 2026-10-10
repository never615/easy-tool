<?php

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;
use Mallto\Tool\Domain\RedisMonitor\RedisMemoryMonitor;

/**
 * 执行一次共享 Redis 内存采样。
 *
 * 命令本身不派发 Job/Task，也不循环等待；Scheduler 每分钟启动一次，采样结束立即返回。
 * Monitor 已把所有异常收口成结构化结果，因此即使 Redis 不可用也返回成功退出码，避免一次
 * 监控故障中断同一分钟的其他 Laravel 调度任务。实际故障会写入 Laravel error 日志。
 */
class RedisMemoryMonitorCommand extends Command
{
    protected $signature = 'tool:redis_memory_monitor {--json : 输出 JSON}';

    protected $description = '采样 Redis 实例内存、CPU 和响应状态，并按阈值写入 Laravel 日志';

    /**
     * 处理「handle」相关的内部协作步骤。
     */
    public function handle(RedisMemoryMonitor $monitor): int
    {
        $result = $monitor->run();

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if (! ($result['enabled'] ?? false)) {
            $this->info('redis memory monitor disabled');

            return self::SUCCESS;
        }

        if (isset($result['instances'])) {
            $this->info(sprintf('redis instance monitor completed: instances=%d failed=%d level=%s',
                count($result['instances']), $result['failed_instances'] ?? 0, $result['level']));
            return self::SUCCESS;
        }

        if (! ($result['ok'] ?? false)) {
            $this->warn('redis memory monitor failed; see Laravel error log');

            return self::SUCCESS;
        }

        $percent = $result['snapshot']['used_percent'] ?? null;
        $percentText = $percent === null ? 'n/a' : ((string) $percent.'%');
        $this->info(sprintf(
            'redis memory monitor completed: level=%s used=%s',
            (string) ($result['level'] ?? 'unknown'),
            $percentText
        ));

        return self::SUCCESS;
    }
}
