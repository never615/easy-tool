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
        $canTraceDetails = (bool) Admin::user()?->can('location_ingress_trace.index');

        return Admin::content(function (Content $content) use ($canTraceDetails): void {
            $content->header('定位诊断中心');
            $content->description('入口量、项目归属、业务阶段漏斗与短期诊断样本');
            $content->body(view('easy-tool::location_ingress_counter.content', [
                'dataUrl' => route('admin_monitor.location_ingress.data'),
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
