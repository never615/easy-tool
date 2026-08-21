<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Mallto\Tool\Domain\App\ClearCacheUsecase;
use Mockery;
use Tests\TestCase;

class ClearCacheUsecaseTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        foreach (['artisan', 'log'] as $facade) {
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

    private function expectCacheMaintenanceCommands(): void
    {
        Log::spy();
        Artisan::shouldReceive('call')->once()->with('cache:clear')->andReturn(0);
        Artisan::shouldReceive('call')->once()->with('swoole-table:flush')->andReturn(0);
        Artisan::shouldReceive('call')->once()->with('cache:clear local_redis')->andReturn(0);
        Artisan::shouldReceive('call')->once()->with('location:mac-cache-warm')->andReturn(0);
    }
}
