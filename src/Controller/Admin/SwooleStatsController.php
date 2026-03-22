<?php
/**
 * Copyright (c) 2019. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 2019/2/13
 * Time: 11:32 AM
 */
class SwooleStatsController
{
    /**
     * Redis Hash 键：存储所有已注册 pod 的心跳信息
     * Field = hostname，Value = JSON({hostname, address, registered_at})
     */
    protected const PODS_REGISTRY_KEY = 'swoole_pods';

    /**
     * Pod 心跳超时（秒）：超过此时间未刷新则视为下线并惰性清理
     */
    protected const POD_TTL = 120;

    /**
     * 跨 pod HTTP 请求的连接/读取超时（秒）
     */
    protected const REMOTE_TIMEOUT = 3;

    public function index()
    {
        // 每次访问都刷新本 pod 在 Redis 中的心跳注册
        $this->registerSelf();

        // ?self_only=1：pod 间内部调用，只返回本机指标（需携带内部 Token）
        if (request()->boolean('self_only')) {
            return $this->handleSelfOnly();
        }

        if ($this->isGrafanaRequest()) {
            return response()->json($this->getGrafanaPayload());
        }

        // ?all_pods=1：聚合所有 pod 的指标
        if (request()->boolean('all_pods')) {
            return $this->handleAllPods();
        }

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
    // 多 Pod 支持
    // =========================================================================

    /**
     * 将本 pod 注册/刷新到 Redis（心跳）
     *
     * 使用 Redis Hash 存储，Field = hostname，避免 KEYS 扫描时的 prefix 干扰。
     */
    protected function registerSelf(): void
    {
        $address = $this->getSelfPodAddress();
        if (!$address) {
            return;
        }
        try {
            Redis::hset(self::PODS_REGISTRY_KEY, gethostname(), json_encode([
                'hostname'      => gethostname(),
                'address'       => $address,
                'registered_at' => time(),
            ]));
        } catch (\Exception $e) {
            Log::warning('SwooleStatsController::registerSelf failed: ' . $e->getMessage());
        }
    }

    /**
     * 获取本 pod 的内部访问地址，供其他 pod 通过 HTTP 调用
     *
     * 优先使用 Kubernetes Downward API 注入的 POD_IP 环境变量；
     * 非 k8s 环境退回到 gethostbyname(hostname)。
     */
    protected function getSelfPodAddress(): ?string
    {
        // POD_IP 由 Kubernetes Downward API 在运行时注入，每 pod 不同，
        // 不能走 config()（config:cache 后所有 pod 会共享同一份缓存值），
        // 直接读 $_ENV / $_SERVER 绕过 Laravel 的配置缓存。
        $ip = $_ENV['POD_IP'] ?? $_SERVER['POD_IP'] ?? gethostbyname(gethostname());
        if (!$ip || in_array($ip, ['127.0.0.1', '::1'], true)) {
            return null;
        }
        $port = config('laravels.listen_port', 5200);
        return "http://{$ip}:{$port}";
    }

    /**
     * 从 Redis 读取所有存活 pod，同时惰性清理已超时的条目
     *
     * @return array [['hostname'=>..., 'address'=>..., 'registered_at'=>...], ...]
     */
    protected function getRegisteredPods(): array
    {
        try {
            $raw = Redis::hgetall(self::PODS_REGISTRY_KEY);
            if (!$raw) {
                return [];
            }
            $cutoff = time() - self::POD_TTL;
            $alive  = [];
            $stale  = [];
            foreach ($raw as $hostname => $json) {
                $pod = json_decode($json, true);
                if ($pod && ($pod['registered_at'] ?? 0) >= $cutoff) {
                    $alive[] = $pod;
                } else {
                    $stale[] = $hostname; // 惰性清理
                }
            }
            if ($stale) {
                Redis::hdel(self::PODS_REGISTRY_KEY, ...$stale);
            }
            return $alive;
        } catch (\Exception $e) {
            Log::warning('SwooleStatsController::getRegisteredPods failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 计算 pod 间内部请求使用的 HMAC Token，防止直接从公网调用 self_only 端点
     */
    protected function getInternalToken(): string
    {
        return hash_hmac('sha256', 'swoole-stats-internal', config('app.key', 'fallback'));
    }

    /**
     * 处理 pod 间内部请求（?self_only=1）
     *
     * 校验 X-Internal-Token Header，通过后返回本机 JSON 指标。
     */
    protected function handleSelfOnly()
    {
        $token = request()->header('X-Internal-Token');
        if ($token !== $this->getInternalToken()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        return response()->json($this->getSwooleMetrics());
    }

    /**
     * 向指定 pod 地址发起内部 HTTP 请求，获取其 Swoole 指标
     *
     * @param  string $address  形如 http://10.x.x.x:5200
     * @return array|null       指标数组，请求失败返回 null
     */
    protected function fetchPodStats(string $address): ?array
    {
        // 路由路径：可在 .env 中设置 SWOOLE_STATS_INTERNAL_PATH 覆盖（需同步写入对应 config 文件）；
        // 默认按 admin.route.prefix 拼接，与 easy-tool routes/web.php 中的路由保持一致。
        $path  = $_ENV['SWOOLE_STATS_INTERNAL_PATH']
            ?? ('/' . trim(config('admin.route.prefix', 'admin'), '/') . '/swoole_stats');
        $url   = rtrim($address, '/') . $path . '?self_only=1';
        $token = $this->getInternalToken();

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::REMOTE_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::REMOTE_TIMEOUT,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                    'X-Internal-Token: ' . $token,
                ],
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response && $httpCode === 200) {
                return json_decode($response, true);
            }
        } catch (\Exception $e) {
            Log::warning("SwooleStatsController::fetchPodStats [{$address}]: " . $e->getMessage());
        }
        return null;
    }

    /**
     * 聚合所有 pod 的指标并返回响应
     *
     * 本 pod 直接读取（无需 HTTP），其余 pod 逐一 cURL 拉取。
     */
    protected function handleAllPods()
    {
        $selfAddress = $this->getSelfPodAddress();
        $pods        = $this->getRegisteredPods();

        // 本 pod 直接取，不走 HTTP
        $results = [[
            'hostname' => gethostname(),
            'address'  => $selfAddress,
            'metrics'  => $this->getSwooleMetrics(),
            'status'   => 'ok',
        ]];

        // 依次拉取其余 pod（可改为并发，当前 pod 数量有限，串行已够用）
        foreach ($pods as $pod) {
            if (($pod['address'] ?? '') === $selfAddress) {
                continue; // 已加入本 pod，跳过
            }
            $metrics   = $this->fetchPodStats($pod['address']);
            $results[] = [
                'hostname' => $pod['hostname'],
                'address'  => $pod['address'],
                'metrics'  => $metrics,
                'status'   => $metrics ? 'ok' : 'unreachable',
            ];
        }

        $accept = request()->header('accept');
        if (request()->ajax() || strpos($accept, 'application/json') !== false) {
            return response()->json($results);
        }
        return $this->renderMultiPodHtml($results);
    }

    /**
     * 渲染多 pod 聚合 HTML 页面，每个 pod 独立一个表格
     *
     * @param array $allPodsStats [['hostname', 'address', 'metrics', 'status'], ...]
     */
    protected function renderMultiPodHtml(array $allPodsStats)
    {
        $podCount  = count($allPodsStats);
        $tablesHtml = '';

        foreach ($allPodsStats as $pod) {
            $hostname    = htmlspecialchars($pod['hostname'] ?? '');
            $address     = htmlspecialchars($pod['address']  ?? '');
            $statusLabel = $pod['status'] === 'ok'
                ? '<span style="color:green">✓ online</span>'
                : '<span style="color:#cc0000">✗ unreachable</span>';

            $rowsHtml = '';
            if (!empty($pod['metrics'])) {
                foreach ($pod['metrics'] as $k => $v) {
                    $rowsHtml .= '<tr><td>' . htmlspecialchars($k) . '</td>'
                        . '<td>' . htmlspecialchars((string)$v) . '</td></tr>';
                }
            } else {
                $rowsHtml = '<tr><td colspan="2" style="color:#999">无法获取数据</td></tr>';
            }

            $tablesHtml .= <<<HTML

            <div class="pod-block">
                <h2>{$hostname}
                    <small style="font-size:0.65em;color:#888;font-weight:normal">{$address}</small>
                    {$statusLabel}
                </h2>
                <table>
                    <thead><tr><th>指标</th><th>值</th></tr></thead>
                    <tbody>{$rowsHtml}</tbody>
                </table>
            </div>
HTML;
        }

        return response()->make(<<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>Swoole 运行状态 — 所有 Pod ({$podCount})</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2em; }
        h1   { margin-bottom: 0.4em; }
        .nav { margin-bottom: 1.8em; }
        .nav a { margin-right: 1.2em; text-decoration: none; color: #0066cc; }
        .pod-block { margin-bottom: 2.5em; }
        h2 { margin-top: 0; margin-bottom: 0.5em; border-bottom: 1px solid #ddd; padding-bottom: 0.4em; }
        table { border-collapse: collapse; width: 60%; }
        th, td { border: 1px solid #ccc; padding: 8px 12px; text-align: left; }
        th { background: #f5f5f5; }
    </style>
</head>
<body>
    <h1>Swoole 运行状态 — 所有 Pod ({$podCount})</h1>
    <div class="nav">
        <a href="?">← 仅当前 Pod</a>
        <a href="?all_pods=1">⟳ 刷新</a>
    </div>
    {$tablesHtml}
</body>
</html>
HTML
            , 200, ['Content-Type' => 'text/html']);
    }

    // =========================================================================
    // 原有方法（保持不变，仅在 renderHtmlWithExtras 中增加多 Pod 导航入口）
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
