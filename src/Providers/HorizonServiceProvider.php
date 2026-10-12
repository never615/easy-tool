<?php
/**
 * Copyright (c) 2020. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Providers;

use Encore\Admin\Facades\Admin;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Laravel\Horizon\WorkerCommandString;
use Illuminate\Queue\QueueManager;
use Mallto\Tool\Domain\Queue\PipelinedHorizonRedisConnector;

/**
 * User: never615 <never615.com>
 * Date: 2020/5/27
 * Time: 7:48 下午
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        parent::boot();

        if (filter_var(config('horizon.redis_migration_pipeline', false), FILTER_VALIDATE_BOOLEAN)) {
            // Boot runs after Horizon registered its connector. Do not resolve queues early or
            // replace an already-live connection; both resident applications need a restart.
            $this->callAfterResolving(QueueManager::class, function ($manager): void {
                $manager->addConnector('redis', function () {
                    return new PipelinedHorizonRedisConnector($this->app['redis']);
                });
            });
        }

        // Per-job console output can fill worker/supervisor pipes before handle(),
        // consuming the Job timeout. Quiet workers retain application logs and
        // Horizon/failed-job events; the master still reports lifecycle messages.
        if (filter_var(config('horizon.worker_quiet', true), FILTER_VALIDATE_BOOLEAN)
            && !preg_match('/(?:^|\s)(?:--quiet|-q)(?:\s|$)/', WorkerCommandString::$command)) {
            WorkerCommandString::$command .= ' --quiet';
        }

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');

        Horizon::night();
    }


    /**
     * Configure the Horizon authorization services.
     *
     * @return void
     */
    protected function authorization()
    {
        Horizon::auth(function ($request) {
            $user = Admin::user();

            return ($user && app()->environment('integration')) ||
                ($user && $user->isOwner());
        });
    }


    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     *
     * @return void
     */
    protected function gate()
    {
        //Gate::define('viewHorizon', function ($user) {
        //    return $user && $user->isOwner();
        //});
    }
}
