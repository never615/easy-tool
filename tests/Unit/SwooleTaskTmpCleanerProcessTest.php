<?php

namespace Mallto\Tool\Tests\Unit;

use Hhxsv5\LaravelS\Swoole\Process\CustomProcessInterface;
use Mallto\Tool\Domain\SwooleTaskTmp\SwooleTaskTmpRuntime;
use Mallto\Tool\Processes\SwooleTaskTmpCleanerProcess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class SwooleTaskTmpCleanerProcessTest extends TestCase
{
    public function test_process_implements_laravels_contract_and_stop_hooks(): void
    {
        $reflection = new ReflectionClass(SwooleTaskTmpCleanerProcess::class);

        $this->assertTrue($reflection->implementsInterface(CustomProcessInterface::class));
        $this->assertTrue($reflection->hasMethod('callback'));
        $this->assertTrue($reflection->hasMethod('onReload'));
        $this->assertTrue($reflection->hasMethod('onStop'));
    }

    public function test_process_uses_runtime_config_and_never_hard_codes_dev_shm(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/src/Processes/SwooleTaskTmpCleanerProcess.php');

        $this->assertIsString($source);
        $this->assertStringContainsString(SwooleTaskTmpRuntime::class, $source);
        $this->assertSame(1, substr_count($source, 'app(SwooleTaskTmpRuntime::class)'));
        $this->assertStringContainsString("config('laravels.swoole.task_tmpdir')", $source);
        $this->assertStringContainsString('sprintf(', $source);
        $this->assertStringContainsString("'%s:%d:%d'", $source);
        $this->assertStringContainsString("['start_time']", $source);
        $this->assertStringContainsString('master_pid', $source);
        $this->assertStringContainsString('getmypid()', $source);
        $this->assertStringNotContainsString('/dev/shm', $source);
        $this->assertStringNotContainsString('env(', $source);
    }

    public function test_process_uses_cooperative_shutdown_and_summary_logs(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/src/Processes/SwooleTaskTmpCleanerProcess.php');

        $this->assertIsString($source);
        $this->assertStringContainsString('static fn (): bool => self::$running', $source);
        $this->assertStringContainsString('self::$running = false;', $source);
        $this->assertStringContainsString('self::sleepWhileRunning', $source);
        $this->assertStringNotContainsString("Log::info(\$path", $source);
        $this->assertStringNotContainsString("Log::warning(\$path", $source);
    }
}
