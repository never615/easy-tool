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
use Laravel\Horizon\Contracts\TagRepository;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Repositories\RedisJobRepository;
use Laravel\Horizon\Repositories\RedisTagRepository;
use Mallto\Tool\Domain\Queue\ChunkedHorizonJobRepository;
use Mallto\Tool\Domain\Queue\EmptyAwareHorizonTagRepository;
use Mallto\Tool\Domain\Queue\PipelinedHorizonRedisConnector;
use Mallto\Tool\Domain\Queue\QueueWorkerControlStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Queue\Console\RestartCommand;
use Laravel\Horizon\Console\WorkCommand as HorizonWorkCommand;

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

        $controlStore = config('horizon.worker_control_cache_store');
        if (is_string($controlStore) && trim($controlStore) !== '') {
            // Laravel/Horizon factories explicitly inject cache.store, bypassing
            // contextual constructor bindings. Extend their real command instances.
            foreach ([WorkCommand::class, HorizonWorkCommand::class, RestartCommand::class] as $commandClass) {
                $this->app->extend($commandClass, function ($command, $app) use ($controlStore, $commandClass) {
                    if (get_class($command) !== $commandClass) return $command;
                    $property = new \ReflectionProperty($command, 'cache');
                    $original = $property->getValue($command);
                    if (get_class($original) !== CacheRepository::class) return $command;
                    $control = $app['cache']->store($controlStore);
                    // Keep custom Repository behavior and global/business injections intact.
                    if ($original === $control || get_class($control) !== CacheRepository::class
                        || $original->getStore() instanceof QueueWorkerControlStore) return $command;
                    $repository = clone $original;
                    $repository->setStore(new QueueWorkerControlStore(
                        $original->getStore(), $control->getStore()));
                    $property->setValue($command, $repository);
                    return $command;
                });
            }
        }

        // All providers have registered by boot. Extend without resolving the
        // repository early or replacing another application's custom implementation.
        $this->app->extend(TagRepository::class, function ($repository) {
            return get_class($repository) === RedisTagRepository::class
                ? new EmptyAwareHorizonTagRepository($repository->redis)
                : $repository;
        });

        $this->app->extend(JobRepository::class, function ($repository) {
            if (get_class($repository) !== RedisJobRepository::class) {
                return $repository;
            }
            $replacement = new ChunkedHorizonJobRepository($repository->redis);
            // Preserve resolved public retention overrides and metadata fields;
            // constructing a new repository alone would reset them to config.
            foreach (get_object_vars($repository) as $property => $value) {
                $replacement->{$property} = $value;
            }
            return $replacement;
        });

        if (filter_var(config('horizon.redis_migration_pipeline', false), FILTER_VALIDATE_BOOLEAN)
            || filter_var(config('horizon.redis_payload_cache', false), FILTER_VALIDATE_BOOLEAN)) {
            // Boot runs after Horizon registered its connector. Do not resolve queues early or
            // replace an already-live connection; both resident applications need a restart.
            $this->callAfterResolving(QueueManager::class, function ($manager): void {
                $manager->addConnector('redis', function () {
                    return new PipelinedHorizonRedisConnector($this->app['redis'], null,
                        filter_var(config('horizon.redis_migration_pipeline', false), FILTER_VALIDATE_BOOLEAN),
                        filter_var(config('horizon.redis_payload_cache', false), FILTER_VALIDATE_BOOLEAN));
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
