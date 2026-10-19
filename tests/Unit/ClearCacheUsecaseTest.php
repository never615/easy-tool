<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Mallto\Tool\Domain\App\ClearCacheUsecase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClearCacheUsecaseTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        foreach (['artisan', 'log', 'redis'] as $facade) {
            Facade::clearResolvedInstance($facade);
        }

        parent::tearDown();
    }

    public function test_clear_cache_skips_location_result_delete_when_no_keys_match(): void
    {
        config()->set('cache.default', 'redis');
        $this->expectCacheMaintenanceCommands();

        $redis = Mockery::mock();
        $redis->expects('keys')->once()->with('l_res_c_*')->andReturn([]);
        $redis->expects('del')->never();
        $this->app->instance('redis', $redis);

        (new ClearCacheUsecase())->clearCache(true);
        $this->addToAssertionCount(1);
    }

    public function test_clear_cache_deletes_matching_location_result_keys(): void
    {
        config()->set('cache.default', 'redis');
        $this->expectCacheMaintenanceCommands();

        $keys = ['l_res_c_subject_1', 'l_res_c_subject_2'];
        $redis = Mockery::mock();
        $redis->expects('keys')->once()->with('l_res_c_*')->andReturn($keys);
        $redis->expects('del')->once()->with($keys);
        $this->app->instance('redis', $redis);

        (new ClearCacheUsecase())->clearCache(true);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('prefixKeys')]
    public function test_prefix_cleanup_deletes_keys_only_on_the_connection_that_found_them(array $localKeys, array $cacheKeys): void
    {
        config()->set('cache.default', 'redis');
        config()->set('cache.prefix', 'c_regression_test');
        $this->expectCacheMaintenanceCommands();

        $local = Mockery::mock();
        $local->expects('keys')->with('c_regression_test:subject_*')->andReturn($localKeys);
        $cache = Mockery::mock();
        $cache->expects('keys')->with('c_regression_test:subject_*')->andReturn($cacheKeys);
        foreach ([[$local, $localKeys], [$cache, $cacheKeys]] as [$connection, $keys]) {
            if ($keys === []) {
                $connection->expects('del')->never();
            } else {
                $connection->expects('del')->once()->with($keys);
            }
        }

        $redis = Mockery::mock();
        $redis->expects('keys')->with('l_res_c_*')->andReturn([]);
        // The default connection may contain identical key names owned by other business data.
        $redis->expects('del')->never();
        $redis->shouldReceive('connection')->with('local_cache')->andReturn($local);
        $redis->shouldReceive('connection')->with('cache')->andReturn($cache);
        $this->app->instance('redis', $redis);
        Redis::swap($redis);

        (new ClearCacheUsecase())->clearCache(true, 'subject_');
        $this->addToAssertionCount(1);
    }

    public static function prefixKeys(): array
    {
        return [
            'same key on separate connections' => [['c_regression_test:subject_1'], ['c_regression_test:subject_1']],
            'only cache has keys' => [[], ['c_regression_test:subject_1', 'c_regression_test:subject_2']],
            'no matching keys' => [[], []],
        ];
    }

    private function expectCacheMaintenanceCommands(): void
    {
        Log::spy();
        Artisan::shouldReceive('call')->once()->with('cache:clear')->andReturn(0);
        Artisan::shouldReceive('call')->once()->with('swoole-table:flush')->andReturn(0);
        Artisan::shouldReceive('call')->once()->with('cache:clear local_redis')->andReturn(0);
        Artisan::shouldReceive('call')->once()->with('location:mac-cache-warm')->andReturn(0);
    }
}
