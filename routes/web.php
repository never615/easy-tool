<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of the routes that are handled
| by your application. Just tell Laravel the URIs it should respond
| to using a Closure or controller method. Build something great!
|
*/

use Illuminate\Support\Facades\Route;

// ---- K8s 健康检查（无中间件，最低开销）----
Route::group(['namespace' => 'Mallto\Tool\Controller'], function () {
    Route::get('health/liveness', 'HealthCheckController@liveness');
    Route::get('health/readiness', 'HealthCheckController@readiness');
});

$attributes = [
    'namespace' => 'Mallto\Tool\Controller',
    'middleware' => ['web'],
];

Route::group($attributes, function ($router) {

//----------------------------------------  管理端开始  -----------------------------------------------

    Route::get("error/{code}", 'ErrorController@index');
    Route::get('swoole_stats', 'Admin\SwooleStatsController@index');
    Route::get('sadmin/woole_stats', 'Admin\SwooleStatsController@index');


    Route::group(['prefix' => config('admin.route.prefix'), "middleware" => ['adminE_base']],
        function ($router) {

            Route::group(["namespace" => 'Admin'], function () {


                Route::group(['middleware' => ['adminE.auto_permission']],
                    function ($router) {  //指定auth的guard为mall

                        Route::get('log', 'SystemLogController@index')
                            ->name("system_logs.index");

                        //第三方接口请求日志
                        Route::resource("third_logs", "ThirdLogController");

                        //自己日志上报接口的日志
                        Route::resource("owner_logs", 'LogController');

                        //接口管理
                        Route::resource('api_pv_managers', 'ApiPvManagerController');
                        //前端页面管理
                        Route::resource('page_pv_manager', 'PagePvManagerController');

                        //标签管理
                        Route::resource("tags", "TagController");

                        //意见反馈
                        Route::resource("feedbacks", "FeedBackController");

                        //开发者管理
                        Route::resource('app_secrets', 'AppSecretController');

                        //开发者角色管理
                        Route::resource('app_secrets_role', 'AppSecretRoleController');

                        //开发者权限管理
                        Route::resource('app_secrets_permission', 'AppSecretPermissionController');

                        //----------------------------  系统配置 开始  -----------------------------------------------
                        Route::resource("wechat_template_ids", "WechatTemplateMsgContoller");
                        //-----ScaffoldController-----------------------  系统配置 结束-----------------------------------------------

                        //----------------------------  页面配置开始  -----------------------------------------------
                        //轮播图
                        Route::resource('page_banners', 'PageBannerController');
                        //模块头图配置
                        Route::resource('ads', 'AdController');

                        //----------------------------  页面配置结束  -----------------------------------------------
                        Route::get('configs/basic', 'ConfigModuleController@basic')
                            ->name('configs.basic');
                        Route::post('configs/basic', 'ConfigModuleController@saveBasic')
                            ->name('configs.basic.save');
                        Route::get('configs/sms', 'ConfigModuleController@sms')
                            ->name('configs.sms');
                        Route::post('configs/sms', 'ConfigModuleController@saveSms')
                            ->name('configs.sms.save');
                        Route::get('configs/location-algorithm', 'ConfigModuleController@locationAlgorithm')
                            ->name('configs.location_algorithm');
                        Route::post('configs/location-algorithm', 'ConfigModuleController@saveLocationAlgorithm')
                            ->name('configs.location_algorithm.save');
                        Route::get('configs/location-maintenance', 'ConfigModuleController@locationMaintenance')
                            ->name('configs.location_maintenance');
                        Route::post('configs/location-maintenance', 'ConfigModuleController@saveLocationMaintenance')
                            ->name('configs.location_maintenance.save');
                        Route::get('configs/beacon-area', 'ConfigModuleController@beaconArea')
                            ->name('configs.beacon_area');
                        Route::post('configs/beacon-area', 'ConfigModuleController@saveBeaconArea')
                            ->name('configs.beacon_area.save');
                        Route::get('configs/location-debug', 'ConfigModuleController@locationDebug')
                            ->name('configs.location_debug');
                        Route::post('configs/location-debug', 'ConfigModuleController@saveLocationDebug')
                            ->name('configs.location_debug.save');
                        Route::resource('configs', 'ConfigController');
                        Route::get('new_configs/publish-restart', 'NewConfigPublishController@index')
                            ->name('new_configs.publish_restart');
                        Route::get('new_configs/env-preview', 'NewConfigController@envPreview')
                            ->name('new_configs.env_preview');
                        Route::post('new_configs/reload', 'NewConfigController@reload')
                            ->name('new_configs.reload');
                        Route::get('new_configs/swoole-task-monitor', 'NewConfigSwooleTaskMonitorController@index')
                            ->name('new_configs.swoole_task_monitor');
                        Route::post('new_configs/swoole-task-monitor', 'NewConfigSwooleTaskMonitorController@save')
                            ->name('new_configs.swoole_task_monitor.save');
                        Route::resource('new_configs', 'NewConfigController');
                        Route::get('queue_diagnostics', 'QueueDiagnosticController@index')
                            ->name('queue_diagnostics.index');
                        Route::post('queue_diagnostics/settings', 'QueueDiagnosticController@saveSettings')
                            ->name('queue_diagnostics.settings');
                        Route::get('swoole_task_monitor', 'SwooleTaskMonitorController@index')
                            ->name('swoole_task_monitor.index');
                        Route::post('swoole_task_monitor/reset', 'SwooleTaskMonitorController@reset')
                            ->name('swoole_task_monitor.reset');
                        Route::get('monitor/horizon', function () {
                            $horizonPath = '/' . trim(config('horizon.path', 'horizon'), '/');

                            return redirect($horizonPath);
                        })->name('admin_monitor.horizon');
                        Route::get('monitor/swoole_stats', 'SwooleStatsController@index')
                            ->name('admin_monitor.swoole_stats');
                        //Route::resource('sms_notifies', 'SmsNotifyController');
                        Route::resource('sms_templates', 'SmsTemplateController');
                        Route::resource('sms_codes', 'SmsCodeController');
                        Route::resource('alert_rules', 'AlertRuleController');
//DummyRoutePlaceholder
//卡券短信管理
                        Route::resource("coupon_sms_templates", 'CouponSmsTemplateController');

                    });


            });

        });

//----------------------------------------  管理端结束  -----------------------------------------------

});
