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

    /**
     * 需要经过验证
     */
    Route::group(['middleware' => ['requestCheck']], function () {


        //意见反馈
        Route::post("feedback", "FeedbackController@store");

        /**
         * 需要经过签名校验
         */
        Route::group(['middleware' => ['authSign']], function () {

        });


        /**
         * 需要经过授权
         */
//        Route::group(['middleware' => ['jwt.auth', 'jwt.refresh']], function () {
        Route::group(['middleware' => ['auth:api']], function () {

            Route::group(["middleware" => ["scopes:mobile-token"]], function () {

            });

            Route::group(["middleware" => ["scope:mobile-token,wechat-token"]], function () {

            });
        });
    });
});





