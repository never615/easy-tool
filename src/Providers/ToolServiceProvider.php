<?php

namespace Mallto\Tool\Providers;

use Carbon\Carbon;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use Mallto\Tool\Domain\Log\Logger;
use Mallto\Tool\Domain\Log\LoggerAliyun;
use Mallto\Tool\Middleware\AuthenticateSign;
use Mallto\Tool\Middleware\RequestCheck;
use Mallto\Tool\Middleware\ThirdApiLogAfter;
use Mallto\Tool\Middleware\ThirdApiLogBefore;

class ToolServiceProvider extends ServiceProvider
{

    /**
     * @var array
     */
    protected $commands = [
        'Mallto\Tool\Commands\InstallCommand',
    ];

    /**
     * The application's route middleware.
     *
     * @var array
     */
    protected $routeMiddleware = [
        "requestCheck" => RequestCheck::class,
        'authSign'     => AuthenticateSign::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected $middlewareGroups = [
        'third_api' => [
            ThirdApiLogBefore::class,
            ThirdApiLogAfter::class,
        ],
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


        $this->appBoot();
        $this->authBoot();
        $this->routeBoot();
    }


    private function appBoot()
    {
        //自定义校验规则 手机号
        Validator::extend('mobile', function ($attribute, $value, $parameters) {
            $mobile_regex = '"^1\d{10}$"';

            return preg_match($mobile_regex, $value);
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

    private function authBoot()
    {
        Passport::routes();
        Passport::tokensExpireIn(Carbon::now()->addDays(15));
//        Passport::refreshTokensExpireIn(Carbon::now()->addDays(30));


        Passport::tokensCan([
            'mobile-token'  => 'mobile token可以访问所有需要用户绑定了手机号才能访问的接口',
            'wechat-token'  => '微信token是通过openId换取的,只能访问部分接口',
            'parking-token' => '停车需要使用到的token',
            'account-token' => "账户操作权限:如重新绑定手机",
        ]);
    }

    private function routeBoot()
    {
        Route::pattern('id', '[0-9]+');
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


        $this->app->singleton(Logger::class, LoggerAliyun::class);
    }

    /**
     * Register the route middleware.
     *
     * @return void
     */
    protected function registerRouteMiddleware()
    {
        // register route middleware.
        foreach ($this->routeMiddleware as $key => $middleware) {
            app('router')->aliasMiddleware($key, $middleware);
        }

        // register middleware group.
        foreach ($this->middlewareGroups as $key => $middleware) {
            app('router')->middlewareGroup($key, $middleware);
        }
    }


}
