<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

/*
|--------------------------------------------------------------------------
| API 路由
|--------------------------------------------------------------------------
|
| 可以在此为应用注册 API 路由。这些
| 路由由 RouteServiceProvider 加载到一个分组中，
| 该分组使用 api 中间件组。现在可以构建 API。
|
*/

use Illuminate\Support\Facades\Route;

$attributes = [
    'namespace'  => 'Mallto\Tool\Controller\Api',
    'prefix'     => 'api',
    'middleware' => [ 'api' ],
];

Route::group($attributes, function ($router) {


    Route::post("log", 'LogController@store');

//    Route::get('swoole_stats', '\Mallto\Tool\Controller\Admin\SwooleStatsController@index');


    Route::group([ 'middleware' => [ 'owner_api' ] ], function () {
        Route::get("qr_image", 'QrController@index');
    });

    /**
     * 需要经过验证
     */
    Route::group([ 'middleware' => [ 'requestCheck' ] ], function () {
        Route::post('webview/params', 'WebviewParamController@store');
        Route::post('webview/params/resolve', 'WebviewParamController@resolve');
    });

    /**
     * 需要经过验证,并记录接口日志
     */
    Route::group([ 'middleware' => [ 'requestCheck', 'owner_api' ] ], function () {
        //意见反馈
        Route::post("feedback", "FeedbackController@store");

        //获取当前系统时间
        Route::get("time/now", "TimeController@now");

        //标签
        Route::get("tag", 'TagController@index');

        //-------------------  页面配置开始 ------------------------
        //轮播图
        Route::resource("page/banner", "PageBannerController", [ "only" => [ 'index' ] ]);
        //页面广告
        Route::resource("page/ad", "AdController", [ "only" => [ 'index' ] ]);
        //模块头图
        Route::resource("page/head_image", "AdController", [ "only" => [ 'index' ] ]);

        //-------------------  页面配置结束 ------------------------

        // WebView 签名校验
        Route::post('webview/verify-sign', 'WebviewSignController@verify');

        /**
         * 需要经过签名校验
         */
        Route::group([ 'middleware' => [ 'authSign_referrer' ] ], function () {

        });

        /**
         * 需要经过授权
         */
        Route::group([ 'middleware' => [ 'auth:api' ] ], function () {


            Route::group([ "middleware" => [ "ability:mobile-token" ] ], function () {

            });

            Route::group([ "middleware" => [ "ability:mobile-token,wechat-token" ] ], function () {

            });
        });
    });
});

