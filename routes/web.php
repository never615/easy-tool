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

$attributes = [
    'namespace' => 'Mallto\Tool\Controller',
    'middleware' => ['web'],
];

Route::group($attributes, function ($router) {

//----------------------------------------  管理端开始  -----------------------------------------------

    Route::get("error/{code}", 'ErrorController@index');


    Route::group(['prefix' => config('admin.route.prefix'), "middleware" => ['adminE_base']],
        function ($router) {

            Route::group(["namespace" => 'Admin'], function () {
                Route::group(['middleware' => ['adminE.auto_permission']],
                    function ($router) {  //指定auth的guard为mall

                        Route::get('log',
                            '\Rap2hpoutre\LaravelLogViewer\LogViewerController@index')
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
                        Route::resource('configs', 'ConfigController');
                        //Route::resource('sms_notifies', 'SmsNotifyController');
                        Route::resource('sms_templates', 'SmsTemplateController');
                        Route::resource('sms_codes', 'SmsCodeController');
                        Route::resource('alert_rules', 'AlertRuleController');
//DummyRoutePlaceholder
//卡券短信管理
                        Route::resource("coupon_sms_templates", 'CouponSmsTemplateController');

                        Route::get('swoole_stats', 'SwooleStatsController@index');
                    });


            });

        });

//----------------------------------------  管理端结束  -----------------------------------------------

});
