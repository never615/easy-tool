<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Mail\TransportManager;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Mallto\Tool\Commands\RedisDelPrefixCommand;
use Mallto\Tool\Domain\Config\Config;
use Mallto\Tool\Domain\Config\MtConfig;
use Mallto\Tool\Domain\Log\Logger;
use Mallto\Tool\Domain\Log\LoggerAliyun;
use Mallto\Tool\Domain\Sms\AliyunSms;
use Mallto\Tool\Domain\Sms\Sms;
use Mallto\Tool\Jobs\LogJob;
use Mallto\Tool\Laravel\Cache\SwooleTableStore;
use Mallto\Tool\Laravel\MemoryStore;
use Mallto\Tool\Mail\AliyunMailTransport;
use Mallto\Tool\Middleware\AuthenticateSign;
use Mallto\Tool\Middleware\AuthenticateSign2;
use Mallto\Tool\Middleware\AuthenticateSignWithReferrer;
use Mallto\Tool\Middleware\OwnerApiLog;
use Mallto\Tool\Middleware\RequestCheck;
use Mallto\Tool\Middleware\ThirdRequestCheck;
use Mallto\Tool\Msg\AliyunMobileDevicePush;
use Mallto\Tool\Msg\MobileDevicePush;
use Mallto\User\Data\User;

class ToolServiceProvider extends ServiceProvider
{

    /**
     * @var array
     */
    protected $commands = [
        'Mallto\Tool\Commands\InstallCommand',
        'Mallto\Tool\Commands\UpdateCommand',
        'Mallto\Tool\Commands\UpdateAppSecretCommand',
        'Mallto\Tool\Commands\ResetTableIdSeqCommand',
        RedisDelPrefixCommand::class,
    ];

    /**
     * The application's route middleware.
     *
     * @var array
     */
    protected $routeMiddleware = [
        'requestCheck'      => RequestCheck::class,
        'authSign'          => AuthenticateSign::class,
        'authSign2'         => AuthenticateSign2::class,
        'authSign_referrer' => AuthenticateSignWithReferrer::class,
        'owner_api'         => OwnerApiLog::class,
        'third_api_check'   => ThirdRequestCheck::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected $middlewareGroups = [
    ];


    /**
     * Boot the service provider.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../migrations');

        $this->loadViewsFrom(__DIR__ . '/../../resources/errors_view', 'tooL_errors');

        $this->loadRoutesFrom(__DIR__ . '/../../routes/web.php');
        $this->loadRoutesFrom(__DIR__ . '/../../routes/api.php');

        if ($this->app->runningInConsole()) {
            //发布view覆盖error页面
            $this->publishes([ __DIR__ . '/../../resources/errors_view' => resource_path('views/errors') ],
                'error-views');
        }

        Cache::extend('memory', function ($app) {
            if (\config('app.env') !== 'local') {
                if (\config('admin.swoole') && ! $this->app->runningInConsole()) {
                    return Cache::repository(new SwooleTableStore());
                } else {
                    return Cache::store('redis');
                }
            } else {
                return Cache::store('file');
            }
        });

        $this->appBoot();
        $this->routeBoot();
        $this->queueBoot();
        $this->scheduleBoot();

        Relation::morphMap([
            'user' => User::class,
        ]);
    }


    private function appBoot()
    {
        //日历默认一个月30天,执行这个,回归真实 carbon升级后模式处理了
        //Carbon::addMonthsWithNoOverflow();
        //Carbon::useMonthsOverflow(false);

        //自定义校验规则 手机号
        Validator::extend('mobile', function ($attribute, $value, $parameters) {
            $mobile_regex = '"^1\d{10}$"';

            return preg_match($mobile_regex, $value);
        });

        /**
         * $parameters 传参设置可以有几位小数,设置几,就是几
         */
        Validator::extend('decimal', function ($attribute, $value, $parameters) {

            $decimalNum = $parameters[0] ?: 1;

            if ( ! is_numeric($value)) {
                return false;
            }

            $tempArr = explode('.', $value);
            if (count($tempArr) == 2 && strlen($tempArr[1]) > $decimalNum) {
                return false;
            }

            return true;
        });

        //自定义响应方法
        Response::macro('nocontent', function () {
            return Response::make('', 204);
        });
        Response::macro('redirect', function ($value) {
            return Response::json([ 'redirectUrl' => $value ]);
        });
    }


    private function routeBoot()
    {
        Route::pattern('id', '[0-9]+');
    }


    private function queueBoot()
    {
        //任务循环前
        Queue::looping(function () {
            while (DB::transactionLevel() > 0) {
                //防止事务没有释放
                \Log::info("queue rollback");
                DB::rollBack();
            }
        });

        if (\config('app.log.queue')) {

            Queue::before(function (JobProcessing $event) {
                $logger = app(Logger::class);
                $logger->logQueue([
                    "connection_name" => $event->connectionName,
                    "status"          => "before",
                    "queue"           => $event->job->getQueue(),
                    "name"            => $event->job->resolveName(),
                ]);
            });

            Queue::after(function (JobProcessed $event) {
                $logger = app(Logger::class);
                $logger->logQueue([
                    "connection_name" => $event->connectionName,
                    "status"          => "after",
                    "queue"           => $event->job->getQueue(),
                    "name"            => $event->job->resolveName(),
                ]);
            });

            //任务失败后
            Queue::failing(function (JobFailed $event) {
                \Log::error("队列任务失败");
                \Log::warning($event->job->payload());
                $logger = app(Logger::class);
                $logger->logQueue([
                    "connection_name" => $event->connectionName,
                    "status"          => "failure",
                    "queue"           => $event->job->getQueue(),
                    "name"            => $event->job->resolveName(),
                    "payload"         => $event->job->getRawBody(),
                ]);

            });

            //异常发生后
            Queue::exceptionOccurred(function ($event) {
                \Log::error("队列任务异常");
                \Log::warning($event->job->payload());
                \Log::warning($event->exception);
                $logger = app(Logger::class);
                $logger->logQueue([
                    "connection_name" => $event->connectionName,
                    "status"          => "exception",
                    "queue"           => $event->job->getQueue(),
                    "name"            => $event->job->resolveName(),
                    "payload"         => $event->job->getRawBody(),
                ]);

            });
        }
    }


    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->booting(function () {
        });

        $this->commands($this->commands);

        $this->registerRouteMiddleware();

        $this->registerMail();

        $this->app->singleton(Logger::class, config('mall.logger', LoggerAliyun::class));
        $this->app->singleton(Sms::class, AliyunSms::class);
        $this->app->singleton(MobileDevicePush::class, AliyunMobileDevicePush::class);
        $this->app->singleton(Config::class, MtConfig::class);
    }


    /**
     * Register the route middleware.
     *
     * @return void
     */
    protected function registerRouteMiddleware()
    {
        // register middleware group.
        foreach ($this->middlewareGroups as $key => $middleware) {
            app('router')->middlewareGroup($key, $middleware);
        }

        // register route middleware.
        foreach ($this->routeMiddleware as $key => $middleware) {
            app('router')->aliasMiddleware($key, $middleware);
        }
    }


    /**
     * Register aliyun mail service
     */
    protected function registerMail()
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/services.php'
            , 'services'
        );
        $this->app->resolving('swift.transport', function (TransportManager $tm) {
            $tm->extend('aliyun_mail', function () {
                $AccessKeyId = config('services.aliyun_mail.AccessKeyId');
                $AccessSecret = config('services.aliyun_mail.AccessSecret');
                $ReplyToAddress = config('services.aliyun_mail.ReplyToAddress');
                $AddressType = config('services.aliyun_mail.AddressType');

                return new AliyunMailTransport($AccessKeyId, $AccessSecret, $ReplyToAddress, $AddressType);
            });
        });
    }


    /**
     * 调度任务
     */
    private function scheduleBoot()
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            if (\config("other.update_app_secret")) {
                $schedule->command('tool:update_app_secret')
                    ->onOneServer()
                    ->daily()
                    ->name("update_app_secret")
                    ->runInBackground()
                    ->withoutOverlapping()
                    ->before(function () {
                        dispatch(new LogJob("logSchedule",
                            [ "slug" => "update_app_secret", "status" => "start" ]));
                    })
                    ->after(function () {
                        dispatch(new LogJob("logSchedule",
                            [ "slug" => "update_app_secret", "status" => "finish" ]));
                    });
            }
        });
    }
}
