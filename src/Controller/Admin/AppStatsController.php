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
 * 控制器只提供页面骨架和只读 JSON 快照。task tmp 清理请求由独立 POST Controller 创建，
 * 实际删除仍由目标 Pod 的常驻进程完成；本控制器的 GET 方法禁止改变运行状态。
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
                'cleanupUrl' => route('admin_monitor.app_stats.task_tmp_cleanup'),
                'csrfToken' => csrf_token(),
                'canCleanup' => (bool) Admin::user()?->can('admin_monitor.app_stats.task_tmp_cleanup'),
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
