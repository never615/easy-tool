<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

use Illuminate\Support\Facades\Route;

$attributes = [
    'namespace'  => 'Mallto\Tool\Controller\Api',
    'prefix'     => 'api',
    'middleware' => ['api'],
];

Route::group($attributes, function ($router) {



    Route::post("log",'LogController@store');

    Route::group(['middleware' => ['owner_api']], function () {
        Route::get("qr_image", 'QrController@index');
    });

    /**
     * 需要经过验证
     */
    Route::group(['middleware' => ['requestCheck', 'owner_api']], function () {
        //意见反馈
        Route::post("feedback", "FeedbackController@store");

        //获取当前系统时间
        Route::get("time/now", "TimeController@now");

        //标签
        Route::get("tag", 'TagController@index');

        //-------------------  页面配置开始 ------------------------
        //轮播图
        Route::resource("page/banner", "PageBannerController", ["only" => ['index']]);
        //页面广告
        Route::resource("page/ad", "AdController", ["only" => ['index']]);
        //模块头图
        Route::resource("page/head_image", "AdController", ["only" => ['index']]);

        //-------------------  页面配置结束 ------------------------

        /**
         * 需要经过签名校验
         */
        Route::group(['middleware' => ['authSign']], function () {

        });


        /**
         * 需要经过授权
         */
        Route::group(['middleware' => ['auth:api']], function () {


            Route::group(["middleware" => ["scopes:mobile-token"]], function () {

            });

            Route::group(["middleware" => ["scope:mobile-token,wechat-token"]], function () {

            });
        });
    });
});





