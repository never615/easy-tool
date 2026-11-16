<?php

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Mallto\Tool\Domain\Log\OwnerApiLogSettings;

/** 按固定截止时间小批删除入站日志，避免长事务和大批量行锁。 */
class PruneOwnerApiLogsCommand extends Command
{
    protected $signature = 'tool:prune_owner_api_logs {--dry-run : 只统计过期日志，不删除}';

    protected $description = '按保留天数清理本地 owner_api_logs 入站接口日志';

    public function handle(): int
    {
        $days = filter_var(app(OwnerApiLogSettings::class)->retentionDays(), FILTER_VALIDATE_INT);
        $batchSize = filter_var(config('owner_api_log.cleanup_batch_size', 500), FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > 36500) {
            $this->error('retention_days 必须是 1-36500 的整数');
            return self::INVALID;
        }
        if ($batchSize === false || $batchSize < 1 || $batchSize > 5000) {
            $this->error('cleanup_batch_size 必须是 1-5000 的整数');
            return self::INVALID;
        }

        $cutoff = now()->subDays($days)->format('Y-m-d H:i:s');
        $expired = static fn () => DB::table('owner_api_logs')->where('created_at', '<', $cutoff);
        if ($this->option('dry-run')) {
            $this->info('Expired: ' . $expired()->count() . '; cutoff: ' . $cutoff);
            return self::SUCCESS;
        }

        $deleted = 0;
        do {
            $ids = $expired()->orderBy('created_at')->orderBy('id')->limit($batchSize)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $deleted += $expired()->whereIn('id', $ids)->delete();
            // 每批独立提交，并给前台查询/定位写入让出短暂窗口。
            usleep(50000);
        } while (true);

        $this->info('Deleted: ' . $deleted . '; cutoff: ' . $cutoff);
        return self::SUCCESS;
    }
}
