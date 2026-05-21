<?php

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;
use Mallto\Tool\Domain\QueueDiagnostic\QueueDiagnosticConfig;
use Mallto\Tool\Domain\QueueDiagnostic\QueueDiagnosticSnapshotter;

class QueueDiagnosticSnapshotCommand extends Command
{
    protected $signature = 'tool:queue_diagnostic_snapshot {--json : 输出 JSON}';

    protected $description = '采样 Redis 内存、keyspace 和队列 backlog，并写入队列诊断窗口';

    public function handle(QueueDiagnosticConfig $config, QueueDiagnosticSnapshotter $snapshotter): int
    {
        if (!$config->enabled() || !$config->snapshotEnabled()) {
            $message = 'queue diagnostic snapshot disabled';
            if ($this->option('json')) {
                $this->line(json_encode([ 'enabled' => false, 'message' => $message ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->info($message);
            }

            return 0;
        }

        $snapshot = $snapshotter->capture();

        if ($this->option('json')) {
            $this->line(json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('queue diagnostic snapshot captured: window=' . $snapshot['window_started_at']);
        }

        return 0;
    }
}
