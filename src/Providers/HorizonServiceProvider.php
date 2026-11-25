<?php
/**
 * 版权所有 (c) 2020. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
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
use Mallto\Tool\Domain\Queue\QuietAwareHorizonWorkCommand;
use Mallto\Tool\Domain\Queue\MigrationBatchHorizonRedisConnector;
use Mallto\Tool\Domain\Queue\SharedHorizonConnector;

/**
 * 作者： never615 <never615.com>
 * 日期： 2020/5/27
 * 时间： 7:48 下午
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{

    /**
     * 启动应用服务。
     *
     * @return void
     */
    public function boot()
    {
        parent::boot();

        // Artisan 在服务提供者启动后解析此命令。保留自定义命令的
        // 子类；仅跳过 --quiet 本就丢弃的输出格式化。
        $this->app->extend(\Laravel\Horizon\Console\WorkCommand::class, function ($command) {
            return get_class($command) === \Laravel\Horizon\Console\WorkCommand::class
                ? QuietAwareHorizonWorkCommand::fromOriginal($command)
                : $command;
        });

        // Suspended on develop until the isolated queue performance work is accepted.
        // $this->app->extend(\Illuminate\Queue\Console\RetryCommand::class, function ($command) {
        //     return get_class($command) === \Illuminate\Queue\Console\RetryCommand::class
        //         ? new \Mallto\Tool\Domain\Queue\BoundedQueueRetryCommand() : $command;
        // });

        // 启动时所有服务提供者均已注册。扩展时不要提前解析仓库，
        // 也不要替换其他应用的自定义实现。
        $this->app->extend(TagRepository::class, function ($repository) {
            return get_class($repository) === RedisTagRepository::class
                ? new EmptyAwareHorizonTagRepository($repository->redis)
                : $repository;
        });

        if (config('location.locator_update.lance_durable_handoff', false) === true) {
            $this->app->extend(JobRepository::class, function ($repository) {
                if (get_class($repository) !== RedisJobRepository::class) {
                    return $repository;
                }
                $replacement = new ChunkedHorizonJobRepository($repository->redis);
                // 保留已解析的公开保留策略覆盖值及元数据字段。
                foreach (get_object_vars($repository) as $property => $value) {
                    $replacement->{$property} = $value;
                }
                return $replacement;
            });
        }

        // 持久交接需要同一连接支持冻结载荷和幂等准入。
        // 启动时 Horizon 已注册连接器。不要提前解析队列，也不要
        // 替换已建立的连接；两个常驻应用均需重启。
        $this->callAfterResolving(QueueManager::class, function ($manager): void {
            $manager->addConnector('horizon-shared', fn () => new SharedHorizonConnector($manager));
            $manager->addConnector('redis', function () {
                if (config('location.locator_update.lance_durable_handoff', false) === true
                    || (!filter_var(config('horizon.redis_migration_pipeline', false), FILTER_VALIDATE_BOOLEAN)
                        && !filter_var(config('horizon.redis_payload_cache', false), FILTER_VALIDATE_BOOLEAN))) {
                    return new MigrationBatchHorizonRedisConnector($this->app['redis']);
                }
                return new PipelinedHorizonRedisConnector($this->app['redis'], null,
                    filter_var(config('horizon.redis_migration_pipeline', false), FILTER_VALIDATE_BOOLEAN),
                    filter_var(config('horizon.redis_payload_cache', false), FILTER_VALIDATE_BOOLEAN));
            });
        });

        // 逐任务控制台输出可能在 handle() 前填满工作进程或监督器管道，
        // 消耗任务超时时间。静默工作进程保留应用日志和
        // Horizon 和失败任务事件；主进程仍上报生命周期消息。
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
     * 配置 Horizon 授权服务。
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
     * 注册 Horizon 授权规则。
     *
     * 此授权规则决定哪些用户可以在非本地环境访问 Horizon。
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
