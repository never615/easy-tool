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
            });

        });

//----------------------------------------  管理端结束  -----------------------------------------------


});





