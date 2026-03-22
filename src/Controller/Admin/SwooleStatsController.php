<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Mallto\Tool\Processes\SwooleStatsCollectorProcess;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2019/2/13
 * Time: 11:32 AM
 */
class SwooleStatsController
{
    /**
     * Pod 数据新鲜度阈值（秒）：超过此时间未更新视为下线
     */
    protected const POD_STALE_SECONDS = 15;

    /**
     * 默认采集持续时间（秒）
     */
    protected const DEFAULT_COLLECT_DURATION = 300;

    public function index()
    {
        // ?start_collect=1&duration=300：开启全 pod 采集
        if (request()->boolean('start_collect')) {
            return $this->handleStartCollect();
        }

        // ?stop_collect=1：停止采集
        if (request()->boolean('stop_collect')) {
            return $this->handleStopCollect();
        }

        if ($this->isGrafanaRequest()) {
            return response()->json($this->getGrafanaPayload());
        }

        // ?all_pods=1：显示所有 pod 聚合状态（从 Redis 读取）
        if (request()->boolean('all_pods')) {
            return $this->handleAllPods();
        }

        // 默认：只显示当前 pod
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

    // =========================================================================
    // 多 Pod 支持（基于 Redis + SwooleStatsCollectorProcess）
    // =========================================================================

    /**
     * 开启采集：设置 Redis 标志位，所有 pod 的 SwooleStatsCollectorProcess 会检测到并开始写入
     */
    protected function handleStartCollect()
    {
        $duration = (int) request()->get('duration', self::DEFAULT_COLLECT_DURATION);
        $duration = max(10, min($duration, 3600)); // 限制 10秒 ~ 1小时

        Redis::setex(SwooleStatsCollectorProcess::COLLECTING_KEY, $duration, time());

        // 返回时自动跳转到 all_pods 页面
        $allPodsUrl = request()->url() . '?all_pods=1';
        return redirect($allPodsUrl);
    }

    /**
     * 停止采集：删除 Redis 标志位
     */
    protected function handleStopCollect()
    {
        Redis::del(SwooleStatsCollectorProcess::COLLECTING_KEY);

        $allPodsUrl = request()->url() . '?all_pods=1';
        return redirect($allPodsUrl);
    }

    /**
     * 查看当前采集剩余时间（秒），-2 表示未在采集
     */
    protected function getCollectingTtl(): int
    {
        $ttl = Redis::ttl(SwooleStatsCollectorProcess::COLLECTING_KEY);
        return $ttl > 0 ? $ttl : -2;
    }

    /**
     * 从 Redis Hash 读取所有 pod 的指标数据，过滤掉过期条目
     *
     * @return array [['hostname'=>..., 'stats'=>[...], 'updated_at'=>..., 'stale'=>bool], ...]
     */
    protected function getAllPodsStats(): array
    {
        $raw = Redis::hgetall(SwooleStatsCollectorProcess::PODS_HASH_KEY);
        if (!$raw) {
            return [];
        }

        $cutoff  = time() - self::POD_STALE_SECONDS;
        $result  = [];
        $staleKeys = [];

        foreach ($raw as $hostname => $json) {
            $pod = json_decode($json, true);
            if (!$pod) {
                $staleKeys[] = $hostname;
                continue;
            }

            $updatedAt = $pod['updated_at'] ?? 0;
            $pod['stale'] = ($updatedAt < $cutoff);

            $result[] = $pod;
        }

        // 惰性清理无法解析的条目（不清理仅过期的，因为采集停止后数据自然变 stale）
        if ($staleKeys) {
            Redis::hdel(SwooleStatsCollectorProcess::PODS_HASH_KEY, ...$staleKeys);
        }

        // 按 hostname 排序，保证显示顺序稳定
        usort($result, fn($a, $b) => ($a['hostname'] ?? '') <=> ($b['hostname'] ?? ''));

        return $result;
    }

    /**
     * 聚合所有 pod 的指标页面
     */
    protected function handleAllPods()
    {
        $collectingTtl = $this->getCollectingTtl();
        $isCollecting  = $collectingTtl > 0;
        $pods          = $this->getAllPodsStats();

        $accept = request()->header('accept');
        if (request()->ajax() || strpos($accept, 'application/json') !== false) {
            return response()->json([
                'collecting'     => $isCollecting,
                'collecting_ttl' => $collectingTtl,
                'pods'           => $pods,
            ]);
        }

        return $this->renderMultiPodHtml($pods, $isCollecting, $collectingTtl);
    }

    /**
     * 渲染多 pod 聚合 HTML 页面
     */
    protected function renderMultiPodHtml(array $allPodsStats, bool $isCollecting, int $collectingTtl)
    {
        $podCount   = count($allPodsStats);
        $baseUrl    = request()->url();
        $tablesHtml = '';

        foreach ($allPodsStats as $pod) {
            $hostname  = htmlspecialchars($pod['hostname'] ?? '');
            $updatedAt = isset($pod['updated_at']) ? date('H:i:s', $pod['updated_at']) : '-';
            $isStale   = $pod['stale'] ?? true;

            $statusLabel = $isStale
                ? '<span style="color:#cc0000">● stale</span>'
                : '<span style="color:green">● live</span>';

            $rowsHtml = '';
            if (!empty($pod['stats'])) {
                foreach ($pod['stats'] as $k => $v) {
                    $rowsHtml .= '<tr><td>' . htmlspecialchars($k) . '</td>'
                        . '<td>' . htmlspecialchars((string) $v) . '</td></tr>';
                }
            } else {
                $rowsHtml = '<tr><td colspan="2" style="color:#999">暂无数据</td></tr>';
            }

            $tablesHtml .= <<<HTML

            <div class="pod-block">
                <h2>{$hostname}
                    {$statusLabel}
                    <small style="font-size:0.6em;color:#888;font-weight:normal">更新于 {$updatedAt}</small>
                </h2>
                <table>
                    <thead><tr><th>指标</th><th>值</th></tr></thead>
                    <tbody>{$rowsHtml}</tbody>
                </table>
            </div>
HTML;
        }

        // 采集控制区
        if ($isCollecting) {
            $collectControlHtml = <<<HTML
            <div class="collect-status" style="background:#e8f5e9;padding:12px 18px;border-radius:6px;margin-bottom:1.5em">
                <strong style="color:green">✓ 正在采集</strong>
                <span style="margin-left:8px">剩余 <strong id="ttl-countdown">{$collectingTtl}</strong> 秒</span>
                <a href="{$baseUrl}?stop_collect=1" style="margin-left:16px;color:#cc0000">■ 停止采集</a>
                <a href="{$baseUrl}?all_pods=1" style="margin-left:16px">⟳ 刷新</a>
            </div>
HTML;
        } else {
            $collectControlHtml = <<<HTML
            <div class="collect-status" style="background:#fff3e0;padding:12px 18px;border-radius:6px;margin-bottom:1.5em">
                <strong style="color:#e65100">○ 未在采集</strong>
                <span style="margin-left:12px">持续时间:</span>
                <select id="duration-select" style="margin-left:4px">
                    <option value="60">1 分钟</option>
                    <option value="300" selected>5 分钟</option>
                    <option value="600">10 分钟</option>
                    <option value="1800">30 分钟</option>
                    <option value="3600">1 小时</option>
                </select>
                <a id="start-btn" href="#" style="margin-left:12px;color:green;font-weight:bold">▶ 开始采集</a>
            </div>
HTML;
        }

        $noDataHint = $podCount === 0
            ? '<p style="color:#999">暂无 pod 数据。请先点击「开始采集」，等待 2~3 秒后刷新页面。</p>'
            : '';

        return response()->make(<<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>Swoole 运行状态 — 所有 Pod</title>
    <style>
        body { font-family: -apple-system, Arial, sans-serif; margin: 2em; }
        h1   { margin-bottom: 0.4em; }
        .nav { margin-bottom: 1em; }
        .nav a { margin-right: 1.2em; text-decoration: none; color: #0066cc; }
        .pod-block { margin-bottom: 2em; }
        h2 { margin-top: 0; margin-bottom: 0.5em; border-bottom: 1px solid #ddd; padding-bottom: 0.4em; font-size: 1.1em; }
        table { border-collapse: collapse; width: 60%; }
        th, td { border: 1px solid #ccc; padding: 6px 12px; text-align: left; }
        th { background: #f5f5f5; }
        select { padding: 2px 6px; }
    </style>
</head>
<body>
    <h1>Swoole 运行状态 — 所有 Pod ({$podCount})</h1>
    <div class="nav">
        <a href="{$baseUrl}">← 仅当前 Pod</a>
    </div>

    {$collectControlHtml}
    {$noDataHint}
    {$tablesHtml}

    <script>
        // 开始采集按钮 → 带 duration 参数跳转
        var startBtn = document.getElementById('start-btn');
        if (startBtn) {
            startBtn.addEventListener('click', function(e) {
                e.preventDefault();
                var duration = document.getElementById('duration-select').value;
                window.location.href = '{$baseUrl}?start_collect=1&duration=' + duration;
            });
        }

        // 倒计时显示
        var ttlEl = document.getElementById('ttl-countdown');
        if (ttlEl) {
            var ttl = parseInt(ttlEl.textContent);
            var timer = setInterval(function() {
                ttl--;
                if (ttl <= 0) {
                    clearInterval(timer);
                    ttlEl.textContent = '0';
                    // 自动刷新
                    setTimeout(function() { window.location.reload(); }, 1000);
                } else {
                    ttlEl.textContent = ttl;
                }
            }, 1000);

            // 采集中自动刷新（每 5 秒）
            setTimeout(function() { window.location.reload(); }, 5000);
        }
    </script>
</body>
</html>
HTML
            , 200, ['Content-Type' => 'text/html']);
    }

    // =========================================================================
    // 原有方法
    // =========================================================================

    /**
     * 是否为 Grafana 请求
     *
     * @return bool
     */
    protected function isGrafanaRequest(): bool
    {
        return (string)request()->get('grafana') === '1';
    }

    /**
     * Grafana 需要的 metrics（参考 SwooleStatsCollector）
     *
     * @return array
     */
    protected function getGrafanaPayload(): array
    {
        return array_merge($this->getGrafanaMetrics(), $this->getGrafanaExtraMetrics());
    }

    /**
     * Swoole 指标转成 Grafana/Prometheus 结构
     *
     * @return array
     */
    protected function getGrafanaMetrics(): array
    {
        $server  = app('swoole');
        $stats   = $server->stats();
        $setting = $server->setting;
        if (!isset($stats['worker_num'])) {
            $stats['worker_num'] = $setting['worker_num'];
        }
        if (!isset($stats['task_worker_num'])) {
            $stats['task_worker_num'] = isset($setting['task_worker_num']) ? $setting['task_worker_num'] : 0;
        }

        return [
            ['name' => 'swoole_cpu_num',              'type' => 'gauge', 'value' => swoole_cpu_num()],
            ['name' => 'swoole_start_time',           'type' => 'gauge', 'value' => $stats['start_time']],
            ['name' => 'swoole_connection_num',       'type' => 'gauge', 'value' => $stats['connection_num']],
            ['name' => 'swoole_request_count',        'type' => 'gauge', 'value' => $stats['request_count']],
            ['name' => 'swoole_worker_num',           'type' => 'gauge', 'value' => $stats['worker_num']],
            ['name' => 'swoole_idle_worker_num',      'type' => 'gauge', 'value' => isset($stats['idle_worker_num']) ? $stats['idle_worker_num'] : 0],
            ['name' => 'swoole_task_worker_num',      'type' => 'gauge', 'value' => $stats['task_worker_num']],
            ['name' => 'swoole_task_idle_worker_num', 'type' => 'gauge', 'value' => isset($stats['task_idle_worker_num']) ? $stats['task_idle_worker_num'] : 0],
            ['name' => 'swoole_tasking_num',          'type' => 'gauge', 'value' => isset($stats['tasking_num']) ? $stats['tasking_num'] : 0],
        ];
    }

    /**
     * 额外指标转 Grafana/Prometheus 结构（子类可重写）
     *
     * @return array
     */
    protected function getGrafanaExtraMetrics(): array
    {
        return [];
    }

    /**
     * 获取 Swoole 运行指标
     *
     * @return array
     */
    protected function getSwooleMetrics(): array
    {
        $server = app('swoole');
        $stats  = $server->stats();

        return array_merge([
            'hostname'       => gethostname(),
            'swoole_cpu_num' => swoole_cpu_num(),
        ], [
            'worker_num_use'      => ($stats['worker_num'] - $stats['idle_worker_num']) . '/' . $stats['worker_num'],
            'task_worker_num_use' => ($stats['task_worker_num'] - $stats['task_idle_worker_num']) . '/' . $stats['task_worker_num'],
//            'worker_num' => $stats['worker_num'],
//            'idle_worker_num' => $stats['idle_worker_num'],
//            'task_worker_num' => $stats['task_worker_num'],
//            'task_idle_worker_num' => $stats['task_idle_worker_num'],
            'start_time'          => $stats['start_time'] . ' (' . date('Y-m-d H:i:s', $stats['start_time']) . ')',
            'connection_num'      => $stats['connection_num'],
            'request_count'       => $stats['request_count'],
            'tasking_num'         => $stats['tasking_num'],
        ], []);
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
            'extra'  => $extraMetrics,
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
     * 顶部增加「查看所有 Pod」快捷入口，方便多 pod 场景一键切换。
     *
     * @param array  $swooleMetrics
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
        .nav { margin-bottom: 1.5em; }
        .nav a { margin-right: 1.2em; text-decoration: none; color: #0066cc; }
    </style>
</head>
<body>
    <div class="nav">
        <a href="?all_pods=1">⊞ 查看所有 Pod</a>
        <a href="?">⟳ 刷新</a>
    </div>
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

    /**
     * 把 key/value 指标转换为 Grafana/Prometheus 结构
     *
     * @param array $metrics
     * @return array
     */
    protected function buildGrafanaMetricsFromMap(array $metrics): array
    {
        $result = [];
        foreach ($metrics as $key => $value) {
            if (is_numeric($value)) {
                $result[] = [
                    'name'  => $key,
                    'type'  => 'gauge',
                    'value' => $value + 0,
                ];
            }
        }
        return $result;
    }
}
