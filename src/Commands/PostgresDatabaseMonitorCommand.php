<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;
use Mallto\Tool\Domain\PostgresMonitor\PostgresDatabaseMonitor;

/**
 * 执行一次 PostgreSQL 轻量运行状态采样。
 */
final class PostgresDatabaseMonitorCommand extends Command
{
    protected $signature = 'tool:postgres_database_monitor {--json : 输出 JSON}';

    protected $description = '采样 PostgreSQL 连接、锁等待与 locators 热表健康状态';

    public function handle(PostgresDatabaseMonitor $monitor): int
    {
        $result = $monitor->run();

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if (! ($result['enabled'] ?? false)) {
            $this->info('PostgreSQL monitor disabled');

            return self::SUCCESS;
        }

        if (! ($result['ok'] ?? false)) {
            $this->warn('PostgreSQL monitor failed; see Laravel error log');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'PostgreSQL monitor completed: level=%s connections=%s/%s headroom=%s',
            (string) ($result['level'] ?? 'unknown'),
            (string) ($result['snapshot']['total_connections'] ?? 'n/a'),
            (string) ($result['snapshot']['normal_connection_capacity'] ?? 'n/a'),
            (string) ($result['snapshot']['normal_connection_headroom'] ?? 'n/a')
        ));

        return self::SUCCESS;
    }
}
