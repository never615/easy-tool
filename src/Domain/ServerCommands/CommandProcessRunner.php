<?php

namespace Mallto\Tool\Domain\ServerCommands;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class CommandProcessRunner
{
    public function run(array $argv, int $timeout, callable $started): array
    {
        $stdout = ''; $stderr = ''; $bytes = 0; $truncated = false;
        $limit = min(262144, max(1, (int)config('server_commands.max_output_bytes', 262144)));
        // Put application-level options before command args, including before a user '--'.
        $process = new Process([PHP_BINARY, base_path('artisan'), '--no-interaction', '--no-ansi', ...$argv],
            base_path(), null, null, min(300, max(1, $timeout)));
        $process->disableOutput(); // Callback owns a bounded buffer; Process must not accumulate its own copy.
        $executor = ['hostname' => gethostname() ?: 'unknown', 'role' => 'horizon/server-commands',
            'worker_pid' => getmypid(), 'environment' => config('app.env'), 'php' => PHP_VERSION,
            'artisan_sha256' => is_file(base_path('artisan')) ? hash_file('sha256', base_path('artisan')) : null];
        $status = 'not_started'; $summary = null;
        try {
            $process->start(function ($type, string $chunk) use (&$stdout, &$stderr, &$bytes, &$truncated, $limit): void {
                $take = substr($chunk, 0, max(0, $limit - $bytes));
                $truncated = $truncated || strlen($take) < strlen($chunk);
                $bytes += strlen($take);
                if ($type === Process::OUT) { $stdout .= $take; } else { $stderr .= $take; }
            });
            $executor['pid'] = $process->getPid();
            $started($executor);
            $exit = $process->wait();
            $status = $exit === 0 ? 'succeeded' : 'failed';
        } catch (ProcessTimedOutException) {
            $status = 'timed_out';
            $summary = '命令超时，已请求停止；副作用未回滚，确认进程结束后再解除占用。';
        } catch (\Throwable) {
            $status = $process->isStarted() ? 'unknown' : 'not_started';
            $summary = $status === 'unknown' ? '执行或结果记录异常，请核查实际实例；未自动重跑。' : 'CLI 子进程未能启动。';
            if ($process->isRunning()) { try { $process->stop(1); } catch (\Throwable) {} }
        }
        $stdout = CommandOutput::sanitize($stdout);
        $stderr = CommandOutput::sanitize($stderr);
        // Masking may expand a short secret into a longer placeholder; bound the final UTF-8 bytes too.
        if (strlen($stdout) + strlen($stderr) > $limit) {
            $truncated = true;
            $stdout = mb_strcut($stdout, 0, $limit, 'UTF-8');
            $stderr = mb_strcut($stderr, 0, $limit - strlen($stdout), 'UTF-8');
        }
        return ['status' => $status, 'exit_code' => $process->getExitCode(), 'summary' => $summary,
            'stdout' => $stdout, 'stderr' => $stderr,
            'truncated' => $truncated, 'executor' => $executor];
    }
}
