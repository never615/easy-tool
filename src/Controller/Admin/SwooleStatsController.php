<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2019/2/13
 * Time: 11:32 AM
 */
class SwooleStatsController
{

    public function index()
    {
        $server = app('swoole');
        $stats = $server->stats();

        $metrics = array_merge(
            array_only($stats, [
                'idle_worker_num',
                'task_idle_worker_num',
                'start_time',
                'connection_num',
                'request_count',
                'worker_num',
                'tasking_num',
                'task_worker_num',
            ]),
            [
                'swoole_cpu_num' => swoole_cpu_num(),
                'hostname' => gethostname(),
            ]);

        // 判断请求类型，决定返回 JSON 还是 HTML 可视化页面
        $accept = request()->header('accept');
        if (request()->ajax() || strpos($accept, 'application/json') !== false) {
            return response()->json($metrics);
        }

        // 返回可视化 HTML 页面
        $metricsJson = addslashes(json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return response()->make(<<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>Swoole 运行状态</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2em; }
        table { border-collapse: collapse; width: 60%; margin-bottom: 2em; }
        th, td { border: 1px solid #ccc; padding: 8px 12px; text-align: left; }
        th { background: #f5f5f5; }
    </style>
</head>
<body>
    <h2>Swoole 运行状态</h2>
    <table>
        <thead><tr><th>指标</th><th>值</th></tr></thead>
        <tbody id="metrics-table"></tbody>
    </table>
    <script>
        // 直接输出 PHP 变量为 JS 变量
        const metrics = JSON.parse('$metricsJson');
        // 渲染表格
        const tbody = document.getElementById('metrics-table');
        Object.entries(metrics).forEach(function(entry) {
            var k = entry[0], v = entry[1];
            var tr = document.createElement('tr');
            tr.innerHTML = '<td>' + k + '</td><td>' + v + '</td>';
            tbody.appendChild(tr);
        });
    </script>
</body>
</html>
HTML
        , 200, ['Content-Type' => 'text/html']);
    }
}
