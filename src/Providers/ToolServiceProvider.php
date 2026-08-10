<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
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
use Mallto\Admin\Domain\SubjectConfig\SubjectConfigRegistry;
use Mallto\Tool\Commands\CreateTableIdSeqCommand;
use Mallto\Tool\Commands\DeleteFailedJobsCommand;
use Mallto\Tool\Commands\MongoDatabaseMonitorCommand;
use Mallto\Tool\Commands\PublishNewConfigCommand;
use Mallto\Tool\Commands\PostgresDatabaseMonitorCommand;
use Mallto\Tool\Commands\QueueDiagnosticSnapshotCommand;
use Mallto\Tool\Commands\RedisDelPrefixCommand;
use Mallto\Tool\Commands\RedisMemoryMonitorCommand;
use Mallto\Tool\Controller\Admin\SelectSource\SelectSourceExtend;
use Mallto\Tool\Controller\Admin\Subject\SubjectConfigExtend;
use Mallto\Tool\Controller\Admin\Subject\SubjectSettingExtend;
use Mallto\Tool\Domain\AppStats\AppStatsPodRepository;
use Mallto\Tool\Domain\AppStats\AppStatsPodWriter;
use Mallto\Tool\Domain\AppStats\AppStatsSnapshotService;
use Mallto\Tool\Domain\AppStats\Contracts\AppStatsSectionProvider;
use Mallto\Tool\Domain\AppStats\Sections\MongoDatabaseAppStatsSection;
use Mallto\Tool\Domain\AppStats\Sections\PostgresDatabaseAppStatsSection;
use Mallto\Tool\Domain\AppStats\Sections\RedisMemoryAppStatsSection;
use Mallto\Tool\Domain\AppStats\SwooleStatsSnapshotFactory;
use Mallto\Tool\Domain\SwooleTaskTmp\SwooleTaskTmpCleanupRequestCoordinator;
use Mallto\Tool\Domain\SwooleTaskTmp\SwooleTaskTmpCleanupRequestCreator;
use Mallto\Tool\Domain\SwooleTaskTmp\SwooleTaskTmpCleanupRequestStore;
use Mallto\Tool\Domain\SwooleTaskTmp\SwooleTaskTmpCleanupRequestStatusReader;
use Mallto\Tool\Domain\SwooleTaskTmp\SwooleTaskTmpRuntime;
use Mallto\Tool\Domain\SwooleTaskTmp\SwooleTaskTmpSnapshotStore;
use Mallto\Tool\Domain\Config\Config;
use Mallto\Tool\Domain\Mqtt\RuntimeClientIdConnectionManager;
use Mallto\Tool\Domain\Config\MtConfig;
use Mallto\Tool\Domain\Log\Logger;
use Mallto\Tool\Domain\Log\LoggerAliyun;
use Mallto\Tool\Domain\MongoMonitor\MongoDatabaseMonitor;
use Mallto\Tool\Domain\QueueDiagnostic\ProvidesQueueDiagnosticContext;
use Mallto\Tool\Domain\QueueDiagnostic\QueueDiagnosticConfig;
use Mallto\Tool\Domain\QueueDiagnostic\QueueDiagnosticRecorder;
use Mallto\Tool\Domain\PostgresMonitor\PostgresDatabaseMonitor;
use Mallto\Tool\Domain\RedisMonitor\RedisMemoryMonitor;
use Mallto\Tool\Domain\SubjectConfig\ToolSubjectConfigDefinitions;
use Mallto\Tool\Jobs\LogJob;
use Mallto\Tool\Middleware\AuthenticateSign;
use Mallto\Tool\Middleware\AuthenticateSign2;
use Mallto\Tool\Middleware\AuthenticateSignWithReferrer;
use Mallto\Tool\Middleware\EnforcePerPageLimit;
use Mallto\Tool\Middleware\OwnerApiLog;
use Mallto\Tool\Middleware\RequestCheck;
use Mallto\Tool\Middleware\SetLanguage;
use Mallto\Tool\Middleware\ThirdRequestCheck;
use Mallto\Tool\Middleware\TokenFromQuery;
use Mallto\Tool\Msg\AliyunMobileDevicePush;
use Mallto\Tool\Msg\MobileDevicePush;
use PhpMqtt\Client\ConnectionManager;
use Psr\Log\LoggerInterface;

/**
 * ToolServiceProvider：easy-tool 的 Laravel 服务提供者。
 * 它统一注册路由、命令、容器绑定和框架事件。
 */
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
        QueueDiagnosticSnapshotCommand::class,
        MongoDatabaseMonitorCommand::class,
        PostgresDatabaseMonitorCommand::class,
        RedisMemoryMonitorCommand::class,
        PublishNewConfigCommand::class,
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

        // AppStats 的管理页与旧入口兼容页共享同一组 Blade。命名空间避免与宿主页面重名，
        // 也使 EasyLocation 后续可以复用兼容视图而不复制 HTML、CSS 和脚本。
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'easy-tool');

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


    /**
     * 处理「appBoot」相关的内部协作步骤。
     */
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


    /**
     * 处理「routeBoot」相关的内部协作步骤。
     */
    private function routeBoot()
    {
        Route::pattern('id', '[0-9]+');
    }


    /**
     * 处理「queueBoot」相关的内部协作步骤。
     */
    private function queueBoot()
    {
        Queue::createPayloadUsing(function ($connection, $queue, array $payload) {
            if (!app(QueueDiagnosticConfig::class)->enabled()) {
                return [];
            }

            $job = $payload['data']['commandName'] ?? null;
            if (!$job instanceof ProvidesQueueDiagnosticContext) {
                return [];
            }

            return [
                'queue_diagnostic_context' => $job->queueDiagnosticContext(),
            ];
        });

        //任务循环前
        Queue::looping(function () {
            while (DB::transactionLevel() > 0) {
                //防止事务没有释放
//                Log::info("queue rollback");
                DB::rollBack();
            }
        });

        Queue::before(function (JobProcessing $event) {
            app(QueueDiagnosticRecorder::class)->before($event);
        });

        Queue::after(function (JobProcessed $event) {
            app(QueueDiagnosticRecorder::class)->after($event);
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
            app(QueueDiagnosticRecorder::class)->failed($event);

            $exception = $event->exception;

            // 反序列化失败的残留队列任务（类已不存在），已在 exceptionOccurred 中删除，跳过错误日志
            if ($exception && str_contains($exception->getMessage(), 'Job is incomplete class')) {
                return;
            }

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
            app(QueueDiagnosticRecorder::class)->exceptionOccurred($event);

            $exception = $event->exception;

            // 自动清理反序列化失败的残留队列任务（类已不存在），直接删除并跳过重试
            if ($exception && str_contains($exception->getMessage(), 'Job is incomplete class')) {
                Log::warning("队列任务类不存在，自动删除: ".$exception->getMessage());
                $event->job->delete();

                return;
            }

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
        $this->mergeConfigFrom(__DIR__ . '/../../config/new_config.php', 'new_config');
        $this->mergeConfigFrom(__DIR__ . '/../../config/runtime_config.php', 'runtime_config');
        $this->mergeConfigFrom(__DIR__ . '/../../config/pagination.php', 'easy_tool.pagination');
        $this->mergeConfigFrom(__DIR__ . '/../../config/subject_config_runtime.php', 'subject_config_runtime');
        $this->mergeConfigFrom(__DIR__ . '/../../config/swoole_task_monitor.php', 'swoole_task_monitor');
        $this->mergeConfigFrom(__DIR__ . '/../../config/webview.php', 'webview');
        $this->mergeConfigFrom(__DIR__ . '/../../config/app_stats.php', 'app_stats');

        $this->registerAppStats();
        $this->registerMongoDatabaseMonitor();
        $this->registerPostgresDatabaseMonitor();
        $this->registerRedisMemoryMonitor();
        $this->registerSubjectConfigDefinitions();
        $this->registerGlobalHttpMiddleware();

        $this->app->bind(ConnectionManager::class, function (Application $app) {
            $configRepository = $app->make('config');
            $config = $configRepository->get('mqtt-client', []);
            $config['runtime_client_id']['prefix'] = $config['runtime_client_id']['prefix']
                ?? $configRepository->get('app.env');
            $config['runtime_client_id']['max_length'] = (int)($config['runtime_client_id']['max_length'] ?? 23);

            return new RuntimeClientIdConnectionManager($app, $config);
        });

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
     * 注册全局分页上限中间件。
     *
     * Web 请求会在 ServiceProvider 启动前解析 HTTP Kernel；Container::extend() 会立即扩展
     * 已解析的 singleton，并在尚未解析时保留扩展器，因此两种启动时机都能生效。
     */
    private function registerGlobalHttpMiddleware(): void
    {
        $this->app->extend(HttpKernelContract::class, function (HttpKernelContract $kernel) {
            if ($kernel instanceof HttpKernel) {
                $kernel->pushMiddleware(EnforcePerPageLimit::class);
            }

            return $kernel;
        });
    }

    /**
     * 注册 AppStats 只读聚合服务。
     *
     * singleton 定义本身不会解析 RedisFactory 或建立 Redis 连接。SnapshotService 第一次被请求时
     * 才解析 repository 与 tagged providers；此时其他业务包的 register() 已完成，后注册的 tag
     * 也会进入同一快照，而 EasyTool 不需要反向依赖 EasyLocation。
     */
    private function registerAppStats(): void
    {
        $this->app->singleton(AppStatsPodWriter::class);
        $this->app->singleton(SwooleTaskTmpSnapshotStore::class);
        $this->app->singleton(SwooleTaskTmpCleanupRequestStore::class);
        $this->app->singleton(SwooleTaskTmpCleanupRequestCoordinator::class, static function (Application $app): SwooleTaskTmpCleanupRequestCoordinator {
            return $app->make(SwooleTaskTmpCleanupRequestStore::class);
        });
        $this->app->singleton(SwooleTaskTmpCleanupRequestCreator::class, static function (Application $app): SwooleTaskTmpCleanupRequestCreator {
            return $app->make(SwooleTaskTmpCleanupRequestStore::class);
        });
        $this->app->singleton(SwooleTaskTmpCleanupRequestStatusReader::class, static function (Application $app): SwooleTaskTmpCleanupRequestStatusReader {
            return $app->make(SwooleTaskTmpCleanupRequestStore::class);
        });
        $this->app->singleton(SwooleTaskTmpRuntime::class);
        $this->app->singleton(AppStatsPodRepository::class, static function (Application $app) {
            return new AppStatsPodRepository(
                $app->make(RedisFactory::class),
                $app->make('config'),
                $app->make(SwooleTaskTmpSnapshotStore::class)
            );
        });
        $this->app->singleton(SwooleStatsSnapshotFactory::class);
        // Redis 内存区块只读 Scheduler 已持久化的一个状态 Key。singleton 与 tag 只登记定义，
        // 不会在 Provider 启动时连接 Redis，也不会创建新的 Scheduler、进程或队列任务。
        // 测试、package discovery 或特殊 bootstrap 可能重复调用 register()；Container::tag() 会
        // 追加而不是去重，因此仅在首次绑定时打 tag，避免聚合层出现两个同名 redis_memory 区块。
        if (! $this->app->bound(RedisMemoryAppStatsSection::class)) {
            $this->app->singleton(RedisMemoryAppStatsSection::class);
            $this->app->tag([RedisMemoryAppStatsSection::class], AppStatsSectionProvider::class);
        }
        // PostgreSQL 区块与 Redis 区块一样，只读 Scheduler 写入的单个共享 Redis Key。
        // 它不会在 AppStats HTTP 请求中建立 PostgreSQL 连接。
        if (! $this->app->bound(PostgresDatabaseAppStatsSection::class)) {
            $this->app->singleton(PostgresDatabaseAppStatsSection::class);
            $this->app->tag([PostgresDatabaseAppStatsSection::class], AppStatsSectionProvider::class);
        }
        // MongoDB 区块同样只读 Scheduler 写入的共享 Redis 快照，不在 HTTP 路径访问 MongoDB。
        if (! $this->app->bound(MongoDatabaseAppStatsSection::class)) {
            $this->app->singleton(MongoDatabaseAppStatsSection::class);
            $this->app->tag([MongoDatabaseAppStatsSection::class], AppStatsSectionProvider::class);
        }
        $this->app->singleton(AppStatsSnapshotService::class, static function (Application $app) {
            return new AppStatsSnapshotService(
                $app->make(AppStatsPodRepository::class),
                $app->make(SwooleTaskTmpCleanupRequestStatusReader::class),
                $app->tagged(AppStatsSectionProvider::class),
                $app->make('config'),
                $app->make(LoggerInterface::class)
            );
        });
    }

    /**
     * 注册一次性 MongoDB 采样器，实际连接只在命令执行时创建。
     */
    private function registerMongoDatabaseMonitor(): void
    {
        $this->app->singleton(MongoDatabaseMonitor::class, static function (Application $app) {
            return new MongoDatabaseMonitor(
                $app->make('db'),
                $app->make(RedisFactory::class),
                $app->make(LoggerInterface::class),
                (array)$app->make('config')->get('database.mongodb_monitor', [])
            );
        });
    }

    /**
     * 注册一次性 PostgreSQL 采样器，实际连接只在命令执行时创建。
     */
    private function registerPostgresDatabaseMonitor(): void
    {
        $this->app->singleton(PostgresDatabaseMonitor::class, static function (Application $app) {
            return new PostgresDatabaseMonitor(
                $app->make('db'),
                $app->make(RedisFactory::class),
                $app->make(LoggerInterface::class),
                (array)$app->make('config')->get('database.postgres_monitor', [])
            );
        });
    }

    /**
     * 注册一次性 Redis 内存采样器，不在 Provider 启动阶段建立 Redis socket。
     *
     * Factory、Logger 和配置都只保存到 singleton 定义中，直到命令真正执行才解析实例。
     * 监控配置统一归宿主 database.php；EasyTool 不读取 env()，从而兼容 config:cache 和
     * LaravelS 常驻进程。该服务不创建循环、进程、Horizon Job 或 Swoole Task。
     */
    private function registerRedisMemoryMonitor(): void
    {
        $this->app->singleton(RedisMemoryMonitor::class, static function (Application $app) {
            return new RedisMemoryMonitor(
                $app->make(RedisFactory::class),
                $app->make(LoggerInterface::class),
                (array)$app->make('config')->get('database.redis_monitor', [])
            );
        });
    }
    /**
     * 处理「registerSubjectConfigDefinitions」相关的内部协作步骤。
     */
    private function registerSubjectConfigDefinitions(): void
    {
        foreach (ToolSubjectConfigDefinitions::modules() as $key => $module) {
            SubjectConfigRegistry::registerModule($key, $module);
        }

        SubjectConfigRegistry::registerDefinitions(ToolSubjectConfigDefinitions::definitions());
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

            $schedule->command('tool:queue_diagnostic_snapshot')
                ->name('queue diagnostic snapshot')
                ->onOneServer()
                ->withoutOverlapping()
                ->everyMinute();

            $this->scheduleRedisMemoryMonitor($schedule);
            $this->schedulePostgresDatabaseMonitor($schedule);
            $this->scheduleMongoDatabaseMonitor($schedule);
        });
    }

    /**
     * 把 Redis 内存采样注册到项目已有 Laravel Scheduler。
     *
     * `onOneServer()` 避免多 Pod 重复采样，`withoutOverlapping(5)` 防止上一次异常悬挂时
     * 继续叠加；五分钟锁过期又不会像默认 24 小时那样让一次进程崩溃长期关闭监控。
     * 刻意不调用 runInBackground()：命令只有四个固定规模 Redis 操作，应由 schedule:run
     * 直接等待完成，避免每分钟再派生一个难以统一跟踪的后台进程。
     */
    private function scheduleRedisMemoryMonitor(Schedule $schedule): void
    {
        if (!(bool)\config('database.redis_monitor.enabled', false)) {
            return;
        }

        $connection = (string)\config('database.redis_monitor.connection', 'redis-monitor');
        if (!is_array(\config('database.redis.' . $connection))) {
            // 启用开关却缺少命名 profile 属于部署配置错误；只在应用启动时写一次，不进入每分钟循环。
            Log::error('Redis memory monitor schedule not registered: connection profile missing', [
                'connection' => $connection,
            ]);

            return;
        }

        $schedule->command('tool:redis_memory_monitor')
            ->name('redis memory monitor')
            ->onOneServer()
            ->withoutOverlapping(5)
            ->everyMinute();
    }

    /**
     * 每分钟采样 PostgreSQL 共享内存统计；locators 关系大小由采样器内部降频到五分钟。
     *
     * 不使用 runInBackground()，避免为一次亚秒级只读查询额外派生进程。连接与 SQL 均有
     * 独立短超时，数据库异常不会阻断同一分钟的其它调度任务。
     */
    private function schedulePostgresDatabaseMonitor(Schedule $schedule): void
    {
        if (! (bool)\config('database.postgres_monitor.enabled', false)) {
            return;
        }

        $connection = (string)\config(
            'database.postgres_monitor.connection',
            \config('database.default')
        );
        if (\config('database.connections.' . $connection . '.driver') !== 'pgsql') {
            Log::error('PostgreSQL monitor schedule not registered: connection is not pgsql', [
                'connection' => $connection,
            ]);

            return;
        }

        $redisConnection = (string)\config(
            'database.postgres_monitor.state_redis_connection',
            'redis-monitor'
        );
        if (! is_array(\config('database.redis.' . $redisConnection))) {
            Log::error('PostgreSQL monitor schedule not registered: state Redis profile missing', [
                'connection' => $redisConnection,
            ]);

            return;
        }

        $schedule->command('tool:postgres_database_monitor')
            ->name('postgres database monitor')
            ->onOneServer()
            ->withoutOverlapping(5)
            ->everyMinute();
    }

    /**
     * 每分钟采样 MongoDB 内存态运行指标，不执行任何数据库、集合或文档扫描。
     */
    private function scheduleMongoDatabaseMonitor(Schedule $schedule): void
    {
        if (! (bool)\config('database.mongodb_monitor.enabled', false)) {
            return;
        }

        $connection = (string)\config(
            'database.mongodb_monitor.connection',
            'mongodb-monitor'
        );
        if (\config('database.connections.' . $connection . '.driver') !== 'mongodb') {
            Log::error('MongoDB monitor schedule not registered: connection is not mongodb', [
                'connection' => $connection,
            ]);

            return;
        }

        $redisConnection = (string)\config(
            'database.mongodb_monitor.state_redis_connection',
            'redis-monitor'
        );
        if (! is_array(\config('database.redis.' . $redisConnection))) {
            Log::error('MongoDB monitor schedule not registered: state Redis profile missing', [
                'connection' => $redisConnection,
            ]);

            return;
        }

        $schedule->command('tool:mongo_database_monitor')
            ->name('mongodb database monitor')
            ->onOneServer()
            ->withoutOverlapping(5)
            ->everyMinute();
    }
}
