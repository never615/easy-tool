<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Mallto\Admin\Facades\AdminE;
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
use Mallto\Tool\Middleware\TokenFromQuery;
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
        DeleteFailedJobsCommand::class,
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
        'token_from_query' => TokenFromQuery::class,
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

        $this->configurePostgresTimeouts();
        $this->registerTransactionGuard();


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


        //支持各种boolean值 true,false,1,0,"1","0","true","false"
        Validator::extend('boolean2', function ($attribute, $value, $parameters) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null;
        });

        Validator::extend('custom_time', function ($attribute, $value, $parameters, $validator) {
            // 匹配HH:MM:SS格式，允许小时为00-99
            return preg_match('/^(0\d|1\d|2\d|3\d|4\d|5\d|6\d|7\d|8\d|9\d):([0-5]\d):([0-5]\d)$/', $value);
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
//                Log::info("queue rollback");
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
//                Log::warning("队列任务失败，MaxAttemptsExceededException");
//                Log::warning($event->job->payload());

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
//                Log::warning("队列任务异常，MaxAttemptsExceededException");
//                Log::warning($event->job->payload());

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
     * 监听 PostgreSQL 连接建立事件，设置会话级超时参数。
     *
     * LaravelS 常驻 Worker + PDO 持久连接场景下，若某个请求的查询因锁等待无限阻塞，
     * 会导致该 Worker 永远无法处理新请求，最终 accept queue 满溢、健康探针 timeout。
     *
     * lock_timeout：等待行锁/表锁超过此时间直接抛出异常（不等 statement_timeout）
     * statement_timeout：单条 SQL 超过此时间直接抛出异常
     *
     * 对应 config/database.php：lock_timeout（默认 8s）、statement_timeout（默认 30s）
     * 需要绕过超时的场景（如数据迁移）可在执行前 SET LOCAL statement_timeout = 0
     */
    private function configurePostgresTimeouts(): void
    {
        $lockTimeout      = config('database.connections.pgsql.lock_timeout', '8s');
        $statementTimeout = config('database.connections.pgsql.statement_timeout', '30s');

        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) use ($lockTimeout, $statementTimeout) {
            if ($event->connection->getDriverName() !== 'pgsql') {
                return;
            }
            try {
                // SET（会话级），确保整个连接生效
                $event->connection->unprepared("SET lock_timeout = '{$lockTimeout}'");
                $event->connection->unprepared("SET statement_timeout = '{$statementTimeout}'");
            } catch (\Throwable $e) {
                // 超时参数设置失败不阻断业务，但需记录
                Log::error('[ToolServiceProvider] PostgreSQL timeout config failed: ' . $e->getMessage());
            }
        });
    }

    /**
     * 注册事务泄漏守卫：每次请求结束时，若 Worker 仍有未提交的事务，强制回滚。
     *
     * easy-tool 的 Handler::render() 已在异常时回滚事务，
     * 但有些场景不经过 render()（如 LaravelS Worker 超时强杀、Fatal Error 等），
     * 此守卫作为最后一道防线，确保每次请求结束时事务处于干净状态。
     */
    private function registerTransactionGuard(): void
    {
        $this->app->terminating(function () {
            try {
                $db = DB::connection();
                if ($db->transactionLevel() > 0) {
                    Log::warning('[TransactionGuard] 检测到请求结束时仍有未提交事务，强制回滚', [
                        'level' => $db->transactionLevel(),
                    ]);
                    while ($db->transactionLevel() > 0) {
                        $db->rollBack();
                    }
                }
            } catch (\Throwable $e) {
                Log::error('[TransactionGuard] rollback failed: ' . $e->getMessage());
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

        $this->app->singleton(Logger::class, config('app.log.logger', LoggerAliyun::class));
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

            // Queue backlog monitor: warn at >=1000, error at >=5000
            $schedule->call(function () {
                try {
                    // Determine connection & queue
                    $connection = 'redis';
                    $queue = config('queue.connections.redis.queue', 'default');

                    $size = Queue::connection($connection)->size($queue);

                    if (is_numeric($size)) {
                        if ($size >= 5000) {
                            Log::error('Queue backlog too high', [
                                'connection' => $connection,
                                'queue' => $queue,
                                'size' => $size,
                                'threshold' => 5000,
                            ]);
                        } elseif ($size >= 1000) {
                            Log::warning('Queue backlog high', [
                                'connection' => $connection,
                                'queue' => $queue,
                                'size' => $size,
                                'threshold' => 1000,
                            ]);
                        }
                    } else {
                        Log::warning('Queue size returned non-numeric', [
                            'connection' => $connection,
                            'queue' => $queue,
                            'size' => $size,
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Queue backlog monitor failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            })
                ->name('queue backlog monitor')
                ->onOneServer()
                ->everyMinute();
//                ->everySecond();
        });
    }
}
