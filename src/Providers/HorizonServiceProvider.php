<?php
/**
 * Copyright (c) 2020. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Providers;

use Encore\Admin\Facades\Admin;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * User: never615 <never615.com>
 * Date: 2020/5/27
 * Time: 7:48 下午
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{

    /**
     * Configure the Horizon authorization services.
     *
     * @return void
     */
    protected function authorization()
    {
        $this->gate();

        Horizon::auth(function ($request) {
            return app()->environment('local') ||
                Gate::check('viewHorizon', [ Admin::user() ]);
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
        Gate::define('viewHorizon', function ($user) {
            //horizon队
            //$user = Admin::user();

            return $user && $user->isOwner();
        });
    }
}
