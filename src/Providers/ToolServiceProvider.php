<?php

namespace Mallto\Tool\Providers;

use Illuminate\Support\ServiceProvider;
use Mallto\Mall\Data\Activity;
use Mallto\Mall\Data\RegisterCoupon;
use Mallto\Mall\Data\Scenic;
use Mallto\Mall\Data\Shop;
use Mallto\Mall\Data\SpecialTopic;
use Mallto\Mall\Data\Store;
use Mallto\Mall\Data\Subject;

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
    }


}
