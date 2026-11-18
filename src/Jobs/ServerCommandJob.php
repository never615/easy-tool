<?php

namespace Mallto\Tool\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Mallto\Tool\Domain\ServerCommands\CommandProcessRunner;
use Mallto\Tool\Domain\ServerCommands\ServerCommandService;
use Mallto\Tool\Domain\ServerCommands\ServerCommandStore;

class ServerCommandJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 330;
    public bool $failOnTimeout = true;

    public function __construct(public string $runId)
    {
        $this->onConnection('server-commands')->onQueue('server-commands');
    }

    public function handle(ServerCommandStore $store, ServerCommandService $service, CommandProcessRunner $runner): void
    {
        try {
            $record = $store->claim($this->runId);
            if (!$record) { return; }
            try { $service->authorizeExecution($record); }
            catch (\Throwable) {
                $store->complete($this->runId, 'not_started', ['summary' => '执行前权限或命令定义校验未通过，CLI 未启动；请重新预览。']);
                return;
            }
            $result = $runner->run($record->payload['argv'], $record->timeout,
                fn (array $executor) => $store->started($this->runId, $executor));
            $store->complete($this->runId, $result['status'], $result);
        } catch (\Throwable) {
            // Do not leak decrypted arguments through Horizon exception reporting.
            $this->failed();
        }
    }

    public function failed(?\Throwable $exception = null): void
    {
        try { app(ServerCommandStore::class)->markUnknown($this->runId, '执行器异常退出或结果未持久化；未自动重跑，请核查实际实例。'); }
        catch (\Throwable) { /* The durable occupied slot still prevents another command. */ }
    }
}
