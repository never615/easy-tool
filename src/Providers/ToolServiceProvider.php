<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Providers;

use Carbon\Carbon;
use Encore\Admin\Facades\Admin;
use Illuminate\Mail\TransportManager;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;
use Mallto\Tool\Domain\Config\Config;
use Mallto\Tool\Domain\Config\MtConfig;
use Mallto\Tool\Domain\Log\Logger;
use Mallto\Tool\Domain\Log\LoggerAliyun;
use Mallto\Tool\Domain\Sms\AliyunSms;
use Mallto\Tool\Domain\Sms\Sms;
use Mallto\Tool\Mail\AliyunMailTransport;
use Mallto\Tool\Middleware\AuthenticateSign;
use Mallto\Tool\Middleware\AuthenticateSign2;
use Mallto\Tool\Middleware\OwnerApiLog;
use Mallto\Tool\Middleware\RequestCheck;
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
    ];

    /**
     * The application's route middleware.
     *
     * @var array
     */
    protected $routeMiddleware = [
        "requestCheck" => RequestCheck::class,
        'authSign'     => AuthenticateSign::class,
        'authSign2'    => AuthenticateSign2::class,
        "owner_api"    => OwnerApiLog::class,
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

        $this->loadMigrationsFrom(__DIR__.'/../../migrations');

        $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        if ($this->app->runningInConsole()) {
            //发布view覆盖error页面
            $this->publishes([__DIR__.'/../../resources/errors_view' => resource_path('views/errors')],
                'error-views');
        }

        $this->appBoot();
        $this->routeBoot();
        $this->queueBoot();
    }


    private function appBoot()
    {
        //日历默认一个月30天,执行这个,回归真实
        Carbon::useMonthsOverflow(false);

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


        Queue::failing(function (JobFailed $event) {
            // $event->connectionName
            // $event->job
            // $event->exception
            Log::info("任务失败");
        });

        //自定义响应方法
        Response::macro('nocontent', function () {
            return Response::make('', 204);
        });
        Response::macro('redirect', function ($value) {
            return Response::json(['redirectUrl' => $value]);
        });
    }


    private function routeBoot()
    {
        Route::pattern('id', '[0-9]+');
    }


    private function queueBoot()
    {
        //吹horizon队列管理看板的进入权限
        Horizon::auth(function ($request) {

            $user = Admin::user();
            if ($user && $user->isOwner()) {
                return true;
            } else {
                return false;
            }
        });

        //任务循环前
        Queue::looping(function () {
            while (DB::transactionLevel() > 0) {
                \Log::debug("queue rollback");
                DB::rollBack();
            }
        });

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
        Queue::exceptionOccurred(function (JobExceptionOccurred $event) {
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

        $this->app->singleton(Logger::class, LoggerAliyun::class);
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
        $this->mergeConfigFrom(__DIR__.'/../../config/services.php'
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


}
