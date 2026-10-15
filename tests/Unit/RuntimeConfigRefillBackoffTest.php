<?php

namespace Tests\Unit;

use Illuminate\Config\Repository as Config;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Sleep;
use Mallto\Tool\Domain\RuntimeConfig\RuntimeConfigIdentity;
use Mallto\Tool\Domain\RuntimeConfig\RuntimeConfigSharedStore;
use Mockery;
use PHPUnit\Framework\TestCase;

final class RuntimeConfigRefillBackoffTest extends TestCase
{
    private Container $previous;

    protected function setUp(): void
    {
        $this->previous = Container::getInstance();
        $app = new Container();
        $app->instance('config', new Config(['runtime_config' => [
            'shared_store' => 'array', 'identity_lock_seconds' => 5,
        ]]));
        Container::setInstance($app);
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        Mockery::close();
        Container::setInstance($this->previous);
        parent::tearDown();
    }

    public function test_contended_readers_back_off_and_reuse_valid_false_value(): void
    {
        $this->assertRefill([null, null, null, null, null, null, null], true, false,
            [10000, 20000, 40000, 80000, 160000, 250000, 250000]);
    }

    public function test_already_refilled_negative_value_needs_no_wait(): void
    {
        $this->assertRefill([], false, null, []);
    }

    private function assertRefill(array $misses, bool $found, mixed $value, array $expectedSleeps): void
    {
        $identity = RuntimeConfigIdentity::global('refill-backoff');
        $expected = ['identity' => $identity->fingerprint(), 'found' => $found, 'value' => $value];
        $factory = Mockery::mock(Factory::class);
        $repository = Mockery::mock(Repository::class);
        $lock = Mockery::mock(Lock::class);
        $factory->shouldReceive('store')->with('array')->once()->andReturn($repository);
        $repository->shouldReceive('lock')->with('runtime_config:lock:'.$identity->fingerprint(), 5)
            ->once()->andReturn($lock);
        $lock->shouldReceive('get')->times(count($misses)+1)->andReturnFalse();
        $lock->shouldNotReceive('release');
        $repository->shouldReceive('get')->with($identity->redisKey())->times(count($misses)+1)
            ->andReturn(...array_merge($misses, [$expected]));
        $repository->shouldNotReceive('put');
        $sleeps = [];
        Sleep::fake();
        Sleep::whenFakingSleep(function ($duration) use (&$sleeps): void {
            $sleeps[] = (int)$duration->totalMicroseconds;
        });
        $actual = (new RuntimeConfigSharedStore($factory))->remember($identity, function (): array {
            $this->fail('A contended reader must reuse the valid refill without querying the source.');
        });
        $this->assertSame($expected, $actual);
        $this->assertSame($expectedSleeps, $sleeps);
    }
}
