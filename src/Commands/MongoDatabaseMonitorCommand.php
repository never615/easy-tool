<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;
use Mallto\Tool\Domain\MongoMonitor\MongoDatabaseMonitor;

/**
 * 执行一次 MongoDB 轻量运行状态采样。
 */
final class MongoDatabaseMonitorCommand extends Command
{
    protected $signature = 'tool:mongo_database_monitor {--json : 输出 JSON}';

    protected $description = '采样 MongoDB 可用性、连接容量与 WiredTiger 缓存状态';

    public function handle(MongoDatabaseMonitor $monitor): int
    {
        $result = $monitor->run();

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if (! ($result['enabled'] ?? false)) {
            $this->info('MongoDB monitor disabled');

            return self::SUCCESS;
        }

        if (! ($result['ok'] ?? false)) {
            $this->warn('MongoDB monitor failed; see Laravel error log');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'MongoDB monitor completed: level=%s connections=%s/%s mode=%s',
            (string) ($result['level'] ?? 'unknown'),
            (string) ($result['snapshot']['current_connections'] ?? 'n/a'),
            (string) ($result['snapshot']['connection_capacity'] ?? 'n/a'),
            (string) ($result['snapshot']['monitoring_mode'] ?? 'unknown')
        ));

        return self::SUCCESS;
    }
}
