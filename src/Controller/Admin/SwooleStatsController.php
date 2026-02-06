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
        $swooleMetrics = $this->getSwooleMetrics();
        $extraMetrics = $this->getExtraMetrics();

        // 判断请求类型，决定返回 JSON 还是 HTML 可视化页面
        $accept = request()->header('accept');
        if (request()->ajax() || strpos($accept, 'application/json') !== false) {
            return response()->json($this->getJsonPayload($swooleMetrics, $extraMetrics));
        }

        return $this->renderHtmlWithExtras(
            $swooleMetrics,
            $this->getExtraHtml($extraMetrics),
            $this->getExtraScript($extraMetrics)
        );
    }

    /**
     * 获取 Swoole 运行指标
     *
     * @return array
     */
    protected function getSwooleMetrics(): array
    {
        $server = app('swoole');
        $stats = $server->stats();

        return array_merge(
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
            ]
        );
    }

    /**
     * 获取额外指标（子类可重写）
     *
     * @return array
     */
    protected function getExtraMetrics(): array
    {
        return [];
    }

    /**
     * JSON 响应结构（子类可重写）
     *
     * @param array $swooleMetrics
     * @param array $extraMetrics
     * @return array
     */
    protected function getJsonPayload(array $swooleMetrics, array $extraMetrics): array
    {
        if (empty($extraMetrics)) {
            return $swooleMetrics;
        }

        return [
            'swoole' => $swooleMetrics,
            'extra' => $extraMetrics,
        ];
    }

    /**
     * 额外 HTML 区块（子类可重写）
     *
     * @param array $extraMetrics
     * @return string
     */
    protected function getExtraHtml(array $extraMetrics): string
    {
        return '';
    }

    /**
     * 额外脚本（子类可重写）
     *
     * @param array $extraMetrics
     * @return string
     */
    protected function getExtraScript(array $extraMetrics): string
    {
        return '';
    }

    /**
     * 渲染 HTML 页面（可扩展额外区块）
     *
     * @param array $swooleMetrics
     * @param string $extraHtml
     * @param string $extraScript
     * @return \Illuminate\Http\Response
     */
    protected function renderHtmlWithExtras(array $swooleMetrics, string $extraHtml = '', string $extraScript = '')
    {
        $swooleMetricsJson = addslashes(json_encode($swooleMetrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return response()->make(<<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>Swoole 运行状态</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2em; }
        h2 { margin-top: 2em; }
        h2:first-of-type { margin-top: 0; }
        table { border-collapse: collapse; width: 60%; margin-bottom: 2em; }
        th, td { border: 1px solid #ccc; padding: 8px 12px; text-align: left; }
        th { background: #f5f5f5; }
    </style>
</head>
<body>
    <h2>Swoole 运行状态</h2>
    <table>
        <thead><tr><th>指标</th><th>值</th></tr></thead>
        <tbody id="swoole-metrics-table"></tbody>
    </table>

    {$extraHtml}

    <script>
        // 渲染 Swoole 指标表格
        const swooleMetrics = JSON.parse('$swooleMetricsJson');
        const swooleTableBody = document.getElementById('swoole-metrics-table');
        Object.entries(swooleMetrics).forEach(function(entry) {
            var k = entry[0], v = entry[1];
            var tr = document.createElement('tr');
            tr.innerHTML = '<td>' + k + '</td><td>' + v + '</td>';
            swooleTableBody.appendChild(tr);
        });

        {$extraScript}
    </script>
</body>
</html>
HTML
        , 200, ['Content-Type' => 'text/html']);
    }
}
