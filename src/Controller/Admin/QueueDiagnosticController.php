<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Routing\Controller;
use Mallto\Tool\Domain\QueueDiagnostic\QueueDiagnosticConfig;
use Mallto\Tool\Domain\QueueDiagnostic\QueueDiagnosticRedisStore;
use Mallto\Tool\Domain\QueueDiagnostic\QueueDiagnosticSnapshotter;

class QueueDiagnosticController extends Controller
{
    public function index(
        QueueDiagnosticConfig $config,
        QueueDiagnosticRedisStore $store,
        QueueDiagnosticSnapshotter $snapshotter
    ) {
        $snapshot = $this->snapshot($config, $store, $snapshotter);
        $payload = [
            'config' => $config->snapshot(),
            'snapshot' => $snapshot,
        ];

        $accept = request()->header('accept');
        if (request()->ajax() || request()->boolean('json') || strpos($accept, 'application/json') !== false) {
            return response()->json($payload);
        }

        return Admin::content(function (Content $content) use ($payload) {
            $content->header('队列诊断监控');
            $content->description('Redis / Horizon');
            $content->body($this->renderHtml($payload));
        });
    }

    private function snapshot(
        QueueDiagnosticConfig $config,
        QueueDiagnosticRedisStore $store,
        QueueDiagnosticSnapshotter $snapshotter
    ): array {
        if (request()->boolean('capture') && $config->enabled() && $config->snapshotEnabled()) {
            return $snapshotter->capture();
        }

        $windowStart = (int)request()->get('window', $config->windowStart());

        return $store->windowSnapshot($windowStart);
    }

    private function renderHtml(array $payload): string
    {
        $config = $payload['config'];
        $snapshot = $payload['snapshot'];
        $enabled = !empty($config['enabled']);
        $statusClass = $enabled ? 'label-success' : 'label-default';
        $statusText = $enabled ? '已开启' : '未开启';
        $captureUrl = request()->url() . '?capture=1';
        $jsonUrl = request()->url() . '?json=1';
        $windowTime = !empty($snapshot['window_started_at'])
            ? date('Y-m-d H:i:s', (int)$snapshot['window_started_at'])
            : '-';

        return <<<HTML
<style>
    .queue-diag-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
    .queue-diag-panel { background: #fff; border: 1px solid #d8dde6; border-radius: 4px; padding: 14px 16px; margin-bottom: 14px; }
    .queue-diag-panel h3 { margin-top: 0; font-size: 16px; font-weight: 600; }
    .queue-diag-table { width: 100%; border-collapse: collapse; }
    .queue-diag-table th, .queue-diag-table td { border-bottom: 1px solid #edf0f5; padding: 7px 8px; text-align: left; vertical-align: top; }
    .queue-diag-table th { width: 36%; color: #5f6b7a; font-weight: 600; background: #fafbfc; }
    .queue-diag-actions { margin: 0 0 14px; }
    .queue-diag-actions a { margin-right: 10px; }
    .queue-diag-empty { color: #8a94a6; padding: 8px 0; }
    @media (max-width: 900px) { .queue-diag-grid { grid-template-columns: 1fr; } }
</style>

<div class="queue-diag-actions">
    <span class="label {$statusClass}">{$statusText}</span>
    <span style="margin-left:8px;color:#667085">当前窗口: {$windowTime}</span>
    <a class="btn btn-xs btn-primary" href="{$captureUrl}">采样并刷新</a>
    <a class="btn btn-xs btn-default" href="{$jsonUrl}">JSON</a>
</div>

<div class="queue-diag-grid">
    <div class="queue-diag-panel">
        <h3>配置状态</h3>
        {$this->renderTable($config)}
    </div>
    <div class="queue-diag-panel">
        <h3>窗口事件</h3>
        {$this->renderTable($snapshot['events'] ?? [])}
    </div>
</div>

<div class="queue-diag-grid">
    <div class="queue-diag-panel">
        <h3>Redis Memory</h3>
        {$this->renderTable($snapshot['redis_memory'] ?? [])}
    </div>
    <div class="queue-diag-panel">
        <h3>队列 Backlog</h3>
        {$this->renderTable($snapshot['queue_sizes'] ?? [])}
    </div>
</div>

<div class="queue-diag-grid">
    <div class="queue-diag-panel">
        <h3>Top Jobs</h3>
        {$this->renderTopRows($snapshot['jobs'] ?? [])}
    </div>
    <div class="queue-diag-panel">
        <h3>数据来源</h3>
        {$this->renderTopRows($snapshot['sources'] ?? [])}
    </div>
</div>

<div class="queue-diag-grid">
    <div class="queue-diag-panel">
        <h3>Payload Top</h3>
        {$this->renderTopRows($snapshot['payload_jobs'] ?? [])}
    </div>
    <div class="queue-diag-panel">
        <h3>慢任务</h3>
        {$this->renderTopRows($snapshot['slow_jobs'] ?? [])}
    </div>
</div>

<div class="queue-diag-grid">
    <div class="queue-diag-panel">
        <h3>大 Payload</h3>
        {$this->renderTopRows($snapshot['large_payload_jobs'] ?? [])}
    </div>
    <div class="queue-diag-panel">
        <h3>失败任务</h3>
        {$this->renderTopRows($snapshot['failed_jobs'] ?? [])}
    </div>
</div>

<div class="queue-diag-grid">
    <div class="queue-diag-panel">
        <h3>异常状态</h3>
        {$this->renderTable($snapshot['anomaly'] ?? [])}
    </div>
    <div class="queue-diag-panel">
        <h3>Keyspace</h3>
        {$this->renderTable($snapshot['keyspace'] ?? [])}
    </div>
</div>

<div class="queue-diag-panel">
    <h3>最近事件</h3>
    {$this->renderTable($snapshot['last_event'] ?? [])}
</div>
HTML;
    }

    private function renderTable(array $rows): string
    {
        if (empty($rows)) {
            return '<div class="queue-diag-empty">暂无数据</div>';
        }

        $html = '<table class="queue-diag-table"><tbody>';
        foreach ($rows as $key => $value) {
            $html .= '<tr><th>' . $this->escape((string)$key) . '</th><td>' . $this->escapeValue($value) . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    private function renderTopRows(array $rows): string
    {
        if (empty($rows)) {
            return '<div class="queue-diag-empty">暂无数据</div>';
        }

        $html = '<table class="queue-diag-table"><thead><tr><th>名称</th><th>分数</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td>' . $this->escape((string)($row['name'] ?? '-')) . '</td><td>' . $this->escape((string)($row['score'] ?? 0)) . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    private function escapeValue($value): string
    {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }

        return $this->escape((string)$value);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
