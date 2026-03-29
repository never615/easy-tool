<?php
/**
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * K8s 健康检查控制器
 *
 * - /health/liveness  — 存活探针：Swoole Worker 能处理 HTTP 请求即为 alive
 * - /health/readiness — 就绪探针：除了 Worker 存活，还需要 DB 连接正常
 *
 * 与 TCP Socket 探针的区别：
 * TCP 探针仅检查端口是否可连接（Swoole Master 始终 accept），无法发现 Worker 全部阻塞的情况。
 * HTTP 探针由 Worker 处理，能真正验证应用层可用性。
 *
 * User: never615 <never615.com>
 * Date: 2026/3/29
 */
class HealthCheckController
{
    /**
     * 存活探针：Swoole Worker 能响应即为存活
     */
    public function liveness(): JsonResponse
    {
        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * 就绪探针：Worker 存活 + 数据库连接正常
     */
    public function readiness(): JsonResponse
    {
        try {
            // 轻量级 DB 检查：1 条 SQL，不查业务表
            DB::connection()->getPdo();

            return response()->json(['status' => 'ok'], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'reason' => 'db_connection_failed',
            ], 503);
        }
    }
}

