<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mallto\Location\Support\Telemetry\LocationIngressCounter;
use Mallto\Tool\Domain\NewConfig\LocationIngressCounterSwitch;
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
        /** @var LocationIngressCounterSwitch $switch */
        $switch = app(LocationIngressCounterSwitch::class);
        $switchState = array_merge($switch->status(), [
            'effective_enabled' => LocationIngressCounter::enabled(),
        ]);
        $canSwitch = (bool) Admin::user()?->can('admin_monitor.location_ingress.switch');

        return Admin::content(function (Content $content) use ($switchState, $canSwitch): void {
            $content->header('定位入口调试');
            $content->description('MQTT / TCP / HTTP / UDP / Mock 的入口与项目归属计数');
            $content->body(view('easy-tool::location_ingress_counter.content', [
                'dataUrl' => route('admin_monitor.location_ingress.data'),
                'switchUrl' => route('admin_monitor.location_ingress.switch'),
                'csrfToken' => csrf_token(),
                'switchState' => $switchState,
                'canSwitch' => $canSwitch,
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

    /**
     * 仅允许拥有独立权限的管理员变更固定的入口计数开关。
     *
     * 浏览器不能提交 Env Key、配置类型或重启参数；领域服务始终以固定 Env 覆盖项发布启动配置。
     */
    public function switch(Request $request): JsonResponse
    {
        if (! Admin::user()?->can('admin_monitor.location_ingress.switch')) {
            return response()->json(['accepted' => false, 'message' => 'forbidden'], 403);
        }

        if (! in_array($request->input('enabled'), ['0', '1', 0, 1], true)) {
            return response()->json(['accepted' => false, 'message' => 'enabled_must_be_boolean'], 422);
        }

        $requestedEnabled = (bool) ((int) $request->input('enabled'));

        try {
            /** @var LocationIngressCounterSwitch $switch */
            $switch = app(LocationIngressCounterSwitch::class);
            $result = $switch->set($requestedEnabled);
            $publish = $result['publish'] ?? null;
            $payload = [
                'accepted' => true,
                'requested_enabled' => array_key_exists('requested_enabled', $result)
                    ? $result['requested_enabled']
                    : $requestedEnabled,
                'effective_enabled' => LocationIngressCounter::enabled(),
            ];

            if ($publish === null) {
                return response()->json($payload + [
                    'publish_status' => 'publish_throttled',
                    'message' => 'publish_throttled',
                ], 429);
            }

            $restart = $publish['restart'] ?? null;
            if (is_array($restart) && ($restart['reason'] ?? null) === 'new_config.restart.enabled is false') {
                return response()->json($payload + [
                    'publish_status' => 'restart_disabled',
                    'message' => 'restart_disabled',
                ]);
            }

            if (is_array($restart) && ($restart['skipped'] ?? false)) {
                return response()->json($payload + [
                    'publish_status' => 'restart_not_scheduled',
                    'message' => 'restart_not_scheduled',
                ]);
            }

            if (is_array($restart) && ! ($restart['skipped'] ?? false)) {
                return response()->json($payload + [
                    'publish_status' => 'restart_scheduled',
                    'message' => 'restart_scheduled',
                ]);
            }

            return response()->json($payload + [
                'publish_status' => 'published',
                'message' => 'published',
            ]);
        } catch (Throwable) {
            return response()->json([
                'accepted' => false,
                'effective_enabled' => LocationIngressCounter::enabled(),
                'publish_status' => 'publish_failed',
                'message' => 'publish_failed',
            ], 503);
        }
    }
}
