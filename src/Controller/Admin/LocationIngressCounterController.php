<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mallto\Location\Support\Telemetry\LocationIngressCounter;
use Throwable;

/**
 * 定位入口流量的人工调试页。
 *
 * 该页面不进入 AppStats 的自动 Pod 采样链；只有运维人员打开并主动刷新（或在当前页勾选
 * 自动刷新）时，才读取固定数量的 Redis 分钟 bucket，避免项目维度明细成为常驻监控负担。
 */
final class LocationIngressCounterController extends Controller
{
    public function index(): mixed
    {
        return Admin::content(function (Content $content): void {
            $content->header('定位入口调试');
            $content->description('MQTT / TCP / HTTP / UDP / Mock 的入口与项目归属计数');
            $content->body(view('easy-tool::location_ingress_counter.content', [
                'dataUrl' => route('admin_monitor.location_ingress.data'),
            ]));
        });
    }

    /**
     * 只读读取最近固定窗口，禁止扫描 Redis 或修改调试开关。
     */
    public function data(Request $request): JsonResponse
    {
        $minutesInput = $request->input('minutes', 10);
        if (filter_var($minutesInput, FILTER_VALIDATE_INT) === false) {
            return response()->json(['message' => 'minutes_must_be_an_integer'], 422);
        }

        $minutes = max(1, min(30, (int)$minutesInput));
        try {
            return response()->json([
                'enabled' => LocationIngressCounter::enabled(),
                'minutes' => $minutes,
                'rows' => LocationIngressCounter::snapshot($minutes),
            ]);
        } catch (Throwable) {
            return response()->json(['message' => 'location_ingress_counter_unavailable'], 503);
        }
    }
}
