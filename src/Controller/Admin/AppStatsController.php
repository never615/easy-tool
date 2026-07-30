<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Mallto\Tool\Domain\AppStats\AppStatsSnapshotService;

/**
 * 统一应用运行监控的 Laravel Admin 入口。
 *
 * 控制器只提供页面骨架和只读 JSON 快照。采集、Redis 内存 INFO、Pod 清理等会修改
 * 运行状态的动作必须留在既有后台采集链路中，禁止由页面请求触发。
 */
final class AppStatsController extends Controller
{
    /**
     * 渲染管理端页面骨架；运行数据由同一权限边界内的 data 接口局部刷新。
     */
    public function index(): mixed
    {
        return Admin::content(function (Content $content): void {
            $content->header('应用运行监控');
            $content->description('LaravelS / Swoole / PostgreSQL / MongoDB / Redis / MQTT');
            $content->body(view('easy-tool::app_stats.content', [
                'mode' => 'admin',
                'dataUrl' => route('admin_monitor.app_stats.data'),
                'initialData' => null,
                'showRedisMemory' => true,
                'autoRefresh' => true,
            ]));
        });
    }

    /**
     * 返回聚合服务原始 schema，不把 null 改成展示字符串，也不在 HTTP 层重新采样。
     */
    public function data(AppStatsSnapshotService $service): JsonResponse
    {
        return response()->json($service->snapshot());
    }
}
