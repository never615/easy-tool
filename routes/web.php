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
    'namespace'  => 'Mallto\Tool\Controller',
    'middleware' => ['web'],
];

Route::group($attributes, function ($router) {

//----------------------------------------  管理端开始  -----------------------------------------------


    Route::group(['prefix' => config('admin.route.prefix'), "middleware" => ['adminE']],
        function ($router) {


            $router->get('log', '\Rap2hpoutre\LaravelLogViewer\LogViewerController@index')->name("log");


            Route::group(["namespace" => 'Admin'], function () {

                //第三方接口请求日志
                Route::resource("third_logs", "ThirdLogController");

                //标签管理
                Route::resource("tags", "TagController");


                //意见反馈
                Route::resource("feedbacks", "FeedBackController");

                //appsecret manager
                Route::resource('app_secrets', 'AppSecretController');


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
                Route::resource('sms_notifies', 'SmsNotifyController');
//DummyRoutePlaceholder

            });

        });

//----------------------------------------  管理端结束  -----------------------------------------------


});





