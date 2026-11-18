<?php

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;
use Mallto\Tool\Domain\ServerCommands\ServerCommandStore;

class PruneServerCommandsCommand extends Command
{
    protected $signature = 'tool:server-commands:prune';
    protected $description = '有界清理已结束的服务器命令正文和审计记录';

    public function handle(ServerCommandStore $store): int
    {
        $this->info('已按保留期限清理正文；删除审计记录：' . $store->prune());
        return self::SUCCESS;
    }
}
