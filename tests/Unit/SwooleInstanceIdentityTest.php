<?php

namespace Mallto\Tool\Tests\Unit;

use Closure;
use Illuminate\Config\Repository;
use Mallto\Tool\Domain\AppStats\SwooleInstanceIdentity;
use PHPUnit\Framework\TestCase;

class SwooleInstanceIdentityTest extends TestCase
{
    public function test_identity_uses_the_shared_laravels_master_pid_file(): void
    {
        $identity = $this->identity(static fn (string $path): string => "36\n");

        $this->assertSame('pod-a:100:36', $identity->make('pod-a', 100));
        $this->assertSame(36, $identity->masterPid());
    }

    public function test_identity_uses_a_deterministic_zero_pid_when_the_shared_pid_file_is_unavailable(): void
    {
        $identity = $this->identity(static fn (string $path): false => false);

        $this->assertSame('pod-a:100:0', $identity->make('pod-a', 100));
        $this->assertSame('pod-a:100:0', $identity->make('pod-a', 100));
    }

    public function test_identity_rejects_invalid_pid_file_contents(): void
    {
        $identity = $this->identity(static fn (string $path): string => 'worker-pid=36');

        $this->assertSame(0, $identity->masterPid());
    }

    /** @param Closure(string): string|false $pidFileReader */
    private function identity(Closure $pidFileReader): SwooleInstanceIdentity
    {
        return new SwooleInstanceIdentity(
            new Repository(['laravels' => ['swoole' => ['pid_file' => '/tmp/laravels.pid']]]),
            $pidFileReader
        );
    }
}
