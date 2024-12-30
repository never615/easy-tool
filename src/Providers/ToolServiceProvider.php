<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Mallto\Admin\Facades\AdminE;
use Mallto\Tool\Commands\ClearCacheCommand;
use Mallto\Tool\Commands\CreateTableIdSeqCommand;
use Mallto\Tool\Commands\DeleteFailedJobsCommand;
use Mallto\Tool\Commands\RedisDelPrefixCommand;
use Mallto\Tool\Controller\Admin\SelectSource\SelectSourceExtend;
use Mallto\Tool\Controller\Admin\Subject\SubjectConfigExtend;
use Mallto\Tool\Controller\Admin\Subject\SubjectSettingExtend;
use Mallto\Tool\Domain\Config\Config;
use Mallto\Tool\Domain\Config\MtConfig;
use Mallto\Tool\Domain\Log\Logger;
use Mallto\Tool\Domain\Log\LoggerAliyun;
use Mallto\Tool\Jobs\LogJob;
use Mallto\Tool\Middleware\AuthenticateSign;
use Mallto\Tool\Middleware\AuthenticateSign2;
use Mallto\Tool\Middleware\AuthenticateSignWithReferrer;
use Mallto\Tool\Middleware\OwnerApiLog;
use Mallto\Tool\Middleware\RequestCheck;
use Mallto\Tool\Middleware\SetLanguage;
use Mallto\Tool\Middleware\ThirdRequestCheck;
use Mallto\Tool\Msg\AliyunMobileDevicePush;
use Mallto\Tool\Msg\MobileDevicePush;

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
        CreateTableIdSeqCommand::class,
        ClearCacheCommand::class,
        DeleteFailedJobsCommand::class
    ];

    /**
     * The application's route middleware.
     *
     * @var array
     */
    protected $routeMiddleware = [
        'requestCheck' => RequestCheck::class,
        'authSign' => AuthenticateSign::class,
        'authSign2' => AuthenticateSign2::class,
        'authSign_referrer' => AuthenticateSignWithReferrer::class,
        'owner_api' => OwnerApiLog::class,
        'third_api_check' => ThirdRequestCheck::class,
        'set_language' => SetLanguage::class,
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
        $this->loadRoutesFrom(__DIR__ . '/../../routes/adminapi.php');

        if ($this->app->runningInConsole()) {
            //发布view覆盖error页面
            $this->publishes([__DIR__ . '/../../resources/errors_view' => resource_path('views/errors')],
                'error-views');

            //发布 view 覆盖 laravel-log 的页面
            $this->publishes([
                __DIR__ . '/../../resources/laravel_log_view' => resource_path('views/vendor/laravel-log-viewer'),
            ], 'laravel-log-views');

            $this->publishes([__DIR__ . '/../../resources/assets/laravel-log' => public_path('vendor/laravel-log')],
                'laravel-log-assets');
        }

        AdminE::extendSubjectConfigClass(SubjectConfigExtend::class);
        AdminE::extendSubjectSettingClass(SubjectSettingExtend::class);

        $this->appBoot();
        $this->routeBoot();
        //$this->authBoot();
        $this->queueBoot();
        $this->scheduleBoot();


    }


    /**
     * 移动到了AuthServiceProvider处理，配合配置到laravels每次请求重置
     */
    private function authBoot()
    {

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

            if (!is_numeric($value)) {
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
            return Response::json(['redirectUrl' => $value]);
        });
        AdminE::extendSelectSourceClass('easy-tool', SelectSourceExtend::class);
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
                Log::info("queue rollback");
                DB::rollBack();
            }
        });

        if (\config('app.log.queue')) {

            Queue::before(function (JobProcessing $event) {
                $logger = app(Logger::class);
                $logger->logQueue([
                    "connection_name" => $event->connectionName,
                    "status" => "before",
                    "queue" => $event->job->getQueue(),
                    "name" => $event->job->resolveName(),
                    "payload" => json_encode($event->job->payload()),
                ]);
            });

            Queue::after(function (JobProcessed $event) {
                $logger = app(Logger::class);
                $logger->logQueue([
                    "connection_name" => $event->connectionName,
                    "status" => "after",
                    "queue" => $event->job->getQueue(),
                    "name" => $event->job->resolveName(),
                ]);
            });
        }

        //任务失败后
        Queue::failing(function (JobFailed $event) {

            $exception = $event->exception;

            if ($exception && $exception instanceof MaxAttemptsExceededException) {
                Log::warning("队列任务失败，MaxAttemptsExceededException");
                Log::warning($event->job->payload());

                return;
            }

            Log::error("队列任务失败");
            Log::warning($event->job->payload());
            Log::warning($event->exception);

            if (\config('app.log.queue')) {
                $logger = app(Logger::class);
                $logger->logQueue([
                    "connection_name" => $event->connectionName,
                    "status" => "failure",
                    "queue" => $event->job->getQueue(),
                    "name" => $event->job->resolveName(),
                    "payload" => $event->job->getRawBody(),
                ]);
            }
        });

        //异常发生后
        Queue::exceptionOccurred(function (JobExceptionOccurred $event) {
            $exception = $event->exception;
            if ($exception && $exception instanceof MaxAttemptsExceededException) {
                Log::warning("队列任务异常，MaxAttemptsExceededException");
                Log::warning($event->job->payload());

                return;
            }
            Log::error("队列任务异常");
            Log::warning($event->job->payload());
            Log::warning($event->exception);
            if (\config('app.log.queue')) {
                $logger = app(Logger::class);
                $logger->logQueue([
                    "connection_name" => $event->connectionName,
                    "status" => "exception",
                    "queue" => $event->job->getQueue(),
                    "name" => $event->job->resolveName(),
                    "payload" => $event->job->getRawBody(),
                ]);
            }
        });
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
        //$this->app->singleton(Sms::class, AliyunSms::class);
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
//        $this->mergeConfigFrom(__DIR__ . '/../../config/services.php'
//            , 'services'
//        );
//        $this->app->resolving('swift.transport', function (TransportManager $tm) {
//            $tm->extend('aliyun_mail', function () {
//                $AccessKeyId = config('services.aliyun_mail.AccessKeyId');
//                $AccessSecret = config('services.aliyun_mail.AccessSecret');
//                $ReplyToAddress = config('services.aliyun_mail.ReplyToAddress');
//                $AddressType = config('services.aliyun_mail.AddressType');
//
//                return new AliyunMailTransport($AccessKeyId, $AccessSecret, $ReplyToAddress, $AddressType);
//            });
//    });
    }


    /**
     * 调度任务
     */
    private
    function scheduleBoot()
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
                            ["slug" => "update_app_secret", "status" => "start"]));
                    })
                    ->after(function () {
                        dispatch(new LogJob("logSchedule",
                            ["slug" => "update_app_secret", "status" => "finish"]));
                    });
            }

            $schedule->command('sanctum:prune-expired --hours=24')
                ->onOneServer()
                ->daily()
                ->name("token_check")
                ->runInBackground()
                ->withoutOverlapping()
                ->before(function () {
                    dispatch(new LogJob("logSchedule",
                        ["slug" => "token_check", "status" => "start"]));
                })
                ->after(function () {
                    dispatch(new LogJob("logSchedule",
                        ["slug" => "token_check", "status" => "finish"]));
                });

            $schedule = $this->app->make(Schedule::class);

            $schedule->command('tool:clear_cache')
                ->name("clear_cache")
                ->everyFiveMinutes()
                ->runInBackground()
                ->before(function () {
                    dispatch(new LogJob("logSchedule",
                        ["slug" => "clear_cache", "status" => "start"]));
                })
                ->after(function () {
                    dispatch(new LogJob("logSchedule",
                        ["slug" => "clear_cache", "status" => "finish"]));
                });

            $schedule->command('tool:delete_failed_jobs_log')
                ->onOneServer()
                ->dailyAt('04:00')
                ->name("delete_failed_jobs_log")
                ->withoutOverlapping()
                ->runInBackground()
                ->evenInMaintenanceMode()
                ->before(function () {
                    dispatch(new LogJob("logSchedule",
                        ["slug" => "clean_failed_jobs_log", "status" => "start"]));
                })
                ->after(function () {
                    dispatch(new LogJob("logSchedule",
                        ["slug" => "clean_failed_jobs_log", "status" => "finish"]));
                });
        });
    }
}
