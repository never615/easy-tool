<?php

namespace Mallto\Tool\Domain\NewConfig;

use RuntimeException;
use Symfony\Component\Process\Process;

class LaravelSRestartService
{
    public function __construct(
        private ?HorizonTerminateService $horizonTerminateService = null
    ) {
    }

    public function restart(): array
    {
        $strategy = (string)config('new_config.restart.strategy', 'supervisor_autorestart');
        if ($strategy === 'disabled') {
            return [
                'skipped' => true,
                'reason' => 'new_config.restart.strategy is disabled',
                'horizon' => [
                    'skipped' => true,
                    'reason' => 'new_config.restart.strategy is disabled',
                    'masters' => [],
                ],
            ];
        }

        $horizon = $this->requestHorizonTerminate();

        if ($strategy === 'command') {
            $result = $this->scheduleConfiguredCommand();
            $result['horizon'] = $horizon;

            return $result;
        }

        $result = $this->scheduleSupervisorAutorestart();
        $result['horizon'] = $horizon;

        return $result;
    }

    private function scheduleConfiguredCommand(): array
    {
        $command = trim((string)config('new_config.restart.command', ''));
        if ($command === '') {
            return [
                'skipped' => true,
                'reason' => 'new_config.restart.command is empty',
            ];
        }

        return $this->scheduleShellCommand($command, 'command');
    }

    private function scheduleSupervisorAutorestart(): array
    {
        $scriptPath = storage_path('framework/new-config-restart-' . date('YmdHis') . '-' . getmypid() . '.sh');
        $delaySeconds = max(1, (int)config('new_config.restart.delay_seconds', 2));
        $includeHorizon = (bool)config('new_config.restart.include_horizon', true);
        $logPath = $this->writableLogPath();

        $script = $this->renderSupervisorAutorestartScript(
            base_path(),
            storage_path('laravels.pid'),
            $logPath,
            $delaySeconds,
            $includeHorizon
        );

        if (file_put_contents($scriptPath, $script) === false) {
            throw new RuntimeException('Unable to write restart script: ' . $scriptPath);
        }
        chmod($scriptPath, 0750);

        $result = $this->scheduleShellCommand(escapeshellarg($scriptPath), 'supervisor_autorestart');
        $result['script_path'] = $scriptPath;
        $result['log_path'] = $logPath;
        $result['delay_seconds'] = $delaySeconds;
        $result['include_horizon'] = $includeHorizon;

        return $result;
    }

    private function scheduleShellCommand(string $command, string $strategy): array
    {
        $process = Process::fromShellCommandline(
            'nohup /bin/sh -lc ' . escapeshellarg($command) . ' >/dev/null 2>&1 & echo $!',
            base_path(),
            null,
            null,
            5
        );
        $process->run();

        $output = trim($process->getOutput() . PHP_EOL . $process->getErrorOutput());
        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                'LaravelS restart schedule failed: ' . ($output !== '' ? $output : 'empty process output')
            );
        }

        return [
            'skipped' => false,
            'strategy' => $strategy,
            'scheduled' => true,
            'pid' => trim($process->getOutput()) ?: null,
            'output' => $output,
        ];
    }

    private function renderSupervisorAutorestartScript(
        string $appDir,
        string $pidFile,
        string $logPath,
        int $delaySeconds,
        bool $includeHorizon
    ): string {
        $appDir = $this->sh($appDir);
        $pidFile = $this->sh($pidFile);
        $logPath = $this->sh($logPath);
        $includeHorizon = $includeHorizon ? '1' : '0';

        return <<<SH
#!/bin/sh
set +e

APP_DIR={$appDir}
PID_FILE={$pidFile}
LOG_FILE={$logPath}
DELAY_SECONDS={$delaySeconds}
INCLUDE_HORIZON={$includeHorizon}

sleep "\$DELAY_SECONDS"

if ! touch "\$LOG_FILE" 2>/dev/null; then
    LOG_FILE="/tmp/easy-admin-new-config-restart.log"
    touch "\$LOG_FILE" 2>/dev/null || LOG_FILE="/dev/null"
fi

{
    echo "[\$(date '+%F %T')] new_config full restart requested"

    kill_pids() {
        SIGNAL="\$1"
        shift

        for PID in "\$@"; do
            case "\$PID" in
                ''|*[!0-9]*)
                    ;;
                *)
                    kill "-\$SIGNAL" "\$PID" 2>/dev/null || true
                    ;;
            esac
        done
    }

    LARAVELS_PIDS="\$(pgrep -f "\$APP_DIR laravels:" 2>/dev/null | tr '\\n' ' ')"
    HORIZON_PIDS=""

    if [ -f "\$PID_FILE" ]; then
        MASTER_PID="\$(cat "\$PID_FILE" 2>/dev/null || true)"
        case "\$MASTER_PID" in
            ''|*[!0-9]*)
                ;;
            *)
                LARAVELS_PIDS="\$LARAVELS_PIDS \$MASTER_PID"
                ;;
        esac
    fi

    if [ "\$INCLUDE_HORIZON" = "1" ]; then
        HORIZON_PIDS="\$(pgrep -f "php \$APP_DIR/artisan horizon" 2>/dev/null | tr '\\n' ' ')"
    fi

    echo "laravels_pids=\$LARAVELS_PIDS"
    echo "horizon_pids=\$HORIZON_PIDS"

    kill_pids TERM \$LARAVELS_PIDS \$HORIZON_PIDS

    sleep 5

    kill_pids KILL \$LARAVELS_PIDS \$HORIZON_PIDS

    echo "[\$(date '+%F %T')] new_config full restart signal sent"
    rm -f "\$0"
} >> "\$LOG_FILE" 2>&1
SH;
    }

    private function sh(string $value): string
    {
        return "'" . str_replace("'", "'\"'\"'", $value) . "'";
    }

    private function writableLogPath(): string
    {
        $logPath = storage_path('logs/new-config-restart.log');
        if (!file_exists($logPath)) {
            @touch($logPath);
            @chmod($logPath, 0660);
        }

        if (is_writable($logPath)) {
            return $logPath;
        }

        return sys_get_temp_dir() . '/easy-admin-new-config-restart.log';
    }

    private function requestHorizonTerminate(): array
    {
        if (!(bool)config('new_config.restart.terminate_horizon', true)) {
            return [
                'skipped' => true,
                'reason' => 'new_config.restart.terminate_horizon is disabled',
                'masters' => [],
            ];
        }

        return ($this->horizonTerminateService ?? app(HorizonTerminateService::class))->requestTerminate();
    }
}
