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


    Route::group(['prefix' => config('admin.prefix'), "middleware" => ["admin"]], function ($router) {


        //需要授权的
        Route::group(['middleware' => ['admin.auto_permission']], function ($router) {  //指定auth的guard为mall

            Route::group(["namespace"=>'Admin'], function () {

                //第三方接口请求日志
                Route::resource("third_logs", "ThirdLogController");

                //标签管理
                Route::resource("tags", "TagController");


                //意见反馈
                Route::resource("feedbacks", "FeedBackController");
            });

        });
    });

//----------------------------------------  管理端结束  -----------------------------------------------


});





