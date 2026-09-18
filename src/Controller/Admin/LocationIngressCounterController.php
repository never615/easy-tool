<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mallto\Location\Support\Telemetry\LocationIngestDiagnosticSchema;
use Mallto\Location\Support\Telemetry\LocationIngestDiagnosticStore;
use Mallto\Location\Support\Telemetry\LocationIngestDiagnosticsConfig;
use Mallto\Location\Support\Telemetry\LocationIngressCounter;
use Mallto\Location\Support\Telemetry\LocationQueryDiagnosticsConfig;
use Mallto\Location\Support\Telemetry\LocationQueryDiagnosticStore;
use Mallto\Tool\Domain\NewConfig\LocationIngressCounterSwitch;
use Mallto\Tool\Domain\NewConfig\LocationIngressDiagnosticsModeSwitch;
use Mallto\Tool\Domain\NewConfig\LocationQueryDiagnosticsSwitch;
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
        /** @var LocationIngressDiagnosticsModeSwitch $modeSwitch */
        $modeSwitch = app(LocationIngressDiagnosticsModeSwitch::class);
        $diagnosticsModeState = array_merge($modeSwitch->status(), [
            'effective_mode' => LocationIngestDiagnosticsConfig::mode(),
        ]);
        $canModeSwitch = (bool) Admin::user()?->can('admin_monitor.location_ingress.mode');
        $canQueryData = (bool) Admin::user()?->can('admin_monitor.location_query_diagnostics.data');
        $canQuerySwitch = (bool) Admin::user()?->can('admin_monitor.location_query_diagnostics.switch');
        $querySwitchState = [
            'configured' => false,
            'requested_enabled' => null,
            'effective_enabled' => LocationQueryDiagnosticsConfig::enabled(),
            'retention_seconds' => LocationQueryDiagnosticsConfig::retentionSeconds(),
        ];
        if ($canQueryData || $canQuerySwitch) {
            /** @var LocationQueryDiagnosticsSwitch $querySwitch */
            $querySwitch = app(LocationQueryDiagnosticsSwitch::class);
            $querySwitchState = array_merge($querySwitchState, $querySwitch->status());
        }
        $canTraceDetails = (bool) Admin::user()?->can('location_ingress_trace.index');

        return Admin::content(function (Content $content) use (
            $switchState,
            $canSwitch,
            $diagnosticsModeState,
            $canModeSwitch,
            $querySwitchState,
            $canQueryData,
            $canQuerySwitch,
            $canTraceDetails
        ): void {
            $content->header('定位诊断中心');
            $content->description('入口量、项目归属、业务阶段漏斗与短期诊断样本');
            $content->body(view('easy-tool::location_ingress_counter.content', [
                'dataUrl' => route('admin_monitor.location_ingress.data'),
                'switchUrl' => route('admin_monitor.location_ingress.switch'),
                'modeSwitchUrl' => route('admin_monitor.location_ingress.mode'),
                'queryDataUrl' => $canQueryData ? route('admin_monitor.location_query_diagnostics.data') : null,
                'querySwitchUrl' => $canQuerySwitch ? route('admin_monitor.location_query_diagnostics.switch') : null,
                'csrfToken' => csrf_token(),
                'switchState' => $switchState,
                'diagnosticsModeState' => $diagnosticsModeState,
                'canSwitch' => $canSwitch,
                'canModeSwitch' => $canModeSwitch,
                'querySwitchState' => $querySwitchState,
                'canQueryData' => $canQueryData,
                'canQuerySwitch' => $canQuerySwitch,
                'canTraceDetails' => $canTraceDetails,
                'traceListUrl' => $canTraceDetails ? route('location_ingress_trace.index') : null,
                'traceDetailUrlTemplate' => $canTraceDetails
                    ? route('location_ingress_trace.show', ['traceId' => '__trace_id__'])
                    : null,
            ]));
        });
    }

    /**
     * 只读读取最近固定窗口，禁止扫描 Redis 或修改调试开关。
     */
    public function data(Request $request): JsonResponse
    {
        $minutesInput = $request->input('minutes', 10);
        if (!is_scalar($minutesInput) || filter_var($minutesInput, FILTER_VALIDATE_INT) === false) {
            return response()->json(['message' => 'minutes_must_be_an_integer'], 422);
        }

        $minutes = max(1, min(30, (int)$minutesInput));
        try {
            return response()->json([
                'ingress_counter_enabled' => LocationIngressCounter::enabled(),
                'diagnostics_mode' => LocationIngestDiagnosticsConfig::mode(),
                'minutes' => $minutes,
                'ingress_rows' => LocationIngressCounter::snapshot($minutes),
                'stage_rows' => LocationIngestDiagnosticStore::summarySnapshot($minutes),
            ]);
        } catch (Throwable) {
            return response()->json(['message' => 'location_ingest_diagnostics_unavailable'], 503);
        }
    }

    /**
     * 只读读取实时地图查询诊断分钟 bucket；接口永远只读取固定 key，不执行 Redis SCAN。
     */
    public function queryData(Request $request): JsonResponse
    {
        if (! Admin::user()?->can('admin_monitor.location_query_diagnostics.data')) {
            return response()->json(['message' => 'forbidden'], 403);
        }

        $minutesInput = $request->input('minutes', 10);
        if (!is_scalar($minutesInput) || filter_var($minutesInput, FILTER_VALIDATE_INT) === false) {
            return response()->json(['message' => 'minutes_must_be_an_integer'], 422);
        }

        $minutes = (int) $minutesInput;
        if ($minutes < 1 || $minutes > 30) {
            return response()->json(['message' => 'minutes_out_of_range'], 422);
        }
        try {
            return response()->json([
                'enabled' => LocationQueryDiagnosticsConfig::enabled(),
                'retention_seconds' => LocationQueryDiagnosticsConfig::retentionSeconds(),
                'minutes' => $minutes,
                'rows' => LocationQueryDiagnosticStore::snapshot($minutes),
            ]);
        } catch (Throwable) {
            return response()->json(['message' => 'location_query_diagnostics_unavailable'], 503);
        }
    }

    /**
     * 仅允许拥有独立权限的管理员变更实时地图查询诊断开关。
     * 浏览器不能提交 Env Key、保留时长或重启参数；领域服务始终管理固定启动配置。
     */
    public function querySwitch(Request $request): JsonResponse
    {
        if (! Admin::user()?->can('admin_monitor.location_query_diagnostics.switch')) {
            return response()->json(['accepted' => false, 'message' => 'forbidden'], 403);
        }

        if (! in_array($request->input('enabled'), ['0', '1', 0, 1], true)) {
            return response()->json(['accepted' => false, 'message' => 'enabled_must_be_boolean'], 422);
        }

        $requestedEnabled = (bool) ((int) $request->input('enabled'));
        try {
            /** @var LocationQueryDiagnosticsSwitch $switch */
            $switch = app(LocationQueryDiagnosticsSwitch::class);
            $result = $switch->set($requestedEnabled);
            $publish = $result['publish'] ?? null;
            $payload = [
                'accepted' => true,
                'requested_enabled' => array_key_exists('requested_enabled', $result)
                    ? $result['requested_enabled']
                    : $requestedEnabled,
                'effective_enabled' => LocationQueryDiagnosticsConfig::enabled(),
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
                'effective_enabled' => LocationQueryDiagnosticsConfig::enabled(),
                'publish_status' => 'publish_failed',
                'message' => 'publish_failed',
            ], 503);
        }
    }

    /**
     * 有界样本必须经过独立权限读取，聚合接口永远不返回 trace ID 或事件。
     */
    public function traces(Request $request): JsonResponse
    {
        if (! Admin::user()?->can('location_ingress_trace.index')) {
            return response()->json(['message' => 'forbidden'], 403);
        }

        $filters = $this->traceFilters($request);
        if ($filters === null) {
            return response()->json(['message' => 'trace_filters_invalid'], 422);
        }

        try {
            return response()->json([
                'rows' => LocationIngestDiagnosticStore::recentTraceSamples($filters),
            ]);
        } catch (Throwable) {
            return response()->json(['message' => 'location_ingest_diagnostics_unavailable'], 503);
        }
    }

    /**
     * 一条 trace 只读取自己的短期 Redis List，不通过聚合接口泄露明细。
     */
    public function trace(Request $request, string $traceId): JsonResponse
    {
        if (! Admin::user()?->can('location_ingress_trace.index')) {
            return response()->json(['message' => 'forbidden'], 403);
        }
        if (!preg_match('/^(?:loc|fault|skylab|skylab_ota)_[A-Za-z0-9_-]{8,96}$/', $traceId)) {
            return response()->json(['message' => 'trace_id_invalid'], 422);
        }

        try {
            $trace = LocationIngestDiagnosticStore::trace($traceId);
        } catch (Throwable) {
            return response()->json(['message' => 'location_ingest_diagnostics_unavailable'], 503);
        }

        if ($trace === null) {
            return response()->json(['message' => 'trace_not_found'], 404);
        }

        return response()->json($trace);
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

    /**
     * 固定模式发布入口；requested 仅表示发布目标，effective 表示当前常驻进程的真实加载值。
     */
    public function mode(Request $request): JsonResponse
    {
        if (! Admin::user()?->can('admin_monitor.location_ingress.mode')) {
            return response()->json(['accepted' => false, 'message' => 'forbidden'], 403);
        }

        $mode = $request->input('mode');
        if (!is_string($mode) || !in_array($mode, LocationIngressDiagnosticsModeSwitch::MODES, true)) {
            return response()->json(['accepted' => false, 'message' => 'diagnostics_mode_invalid'], 422);
        }

        try {
            /** @var LocationIngressDiagnosticsModeSwitch $switch */
            $switch = app(LocationIngressDiagnosticsModeSwitch::class);
            $result = $switch->set($mode);
            $publish = $result['publish'] ?? null;
            $payload = [
                'accepted' => true,
                'requested_mode' => $result['requested_mode'] ?? $mode,
                'effective_mode' => LocationIngestDiagnosticsConfig::mode(),
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
                'effective_mode' => LocationIngestDiagnosticsConfig::mode(),
                'publish_status' => 'publish_failed',
                'message' => 'publish_failed',
            ], 503);
        }
    }

    /** @return array<string, string|int>|null */
    private function traceFilters(Request $request): ?array
    {
        $dimensions = [
            'origin' => LocationIngestDiagnosticSchema::ORIGINS,
            'transport' => LocationIngestDiagnosticSchema::TRANSPORTS,
            'location_solution' => LocationIngestDiagnosticSchema::LOCATION_SOLUTIONS,
        ];
        $filters = [];
        foreach ($dimensions as $key => $allowed) {
            $value = $request->input($key, 'unknown');
            if (!is_string($value) || !in_array($value, $allowed, true)) {
                return null;
            }
            $filters[$key] = $value;
        }

        $limit = $request->input('limit', 20);
        if (!is_scalar($limit) || filter_var($limit, FILTER_VALIDATE_INT) === false) {
            return null;
        }
        $filters['limit'] = max(1, min(100, (int)$limit));

        return $filters;
    }
}
