<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Routing\Controller;
use Mallto\Tool\Domain\SwooleTaskMonitor\SwooleTaskMonitorStore;
use Throwable;

class SwooleTaskMonitorController extends Controller
{
    public function index(SwooleTaskMonitorStore $store)
    {
        $payload = $this->payload($store);

        if ($this->wantsJsonResponse()) {
            return response()->json($payload);
        }

        return Admin::content(function (Content $content) use ($payload) {
            $content->header('Swoole Task监控');
            $content->description('Task Worker / Dispatch / Runtime');
            $content->body($this->renderHtml($payload));
        });
    }

    public function reset(SwooleTaskMonitorStore $store)
    {
        $store->reset($this->dateFromRequest());

        return redirect()->route('swoole_task_monitor.index', [ 'reset' => 1 ]);
    }

    private function payload(SwooleTaskMonitorStore $store): array
    {
        return [
            'snapshot' => $store->snapshot($this->dateFromRequest()),
            'runtime' => $this->runtimeMetrics(),
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function dateFromRequest(): ?string
    {
        $date = (string)request()->get('date', '');

        return preg_match('/^\d{8}$/', $date) ? $date : null;
    }

    private function wantsJsonResponse(): bool
    {
        if (request()->boolean('json')) {
            return true;
        }

        if (request()->pjax()) {
            return false;
        }

        $accept = (string)request()->header('accept');

        return strpos($accept, 'application/json') !== false
            && strpos($accept, 'text/html') === false;
    }

    private function runtimeMetrics(): array
    {
        $stats = [];
        $setting = config('laravels.swoole', []);
        $source = 'config';

        try {
            if (app()->bound('swoole')) {
                $server = app('swoole');
                $stats = $server->stats() ?: [];
                $setting = $server->setting ?? $setting;
                $source = 'swoole';
            }
        } catch (Throwable $exception) {
            $stats = [
                'error' => $exception->getMessage(),
            ];
        }

        $workerNum = (int)($stats['worker_num'] ?? $stats['workers_total'] ?? $setting['worker_num'] ?? 0);
        $idleWorkerNum = (int)($stats['idle_worker_num'] ?? $stats['workers_idle'] ?? 0);
        $taskWorkerNum = (int)($stats['task_worker_num'] ?? $stats['task_workers_total'] ?? $setting['task_worker_num'] ?? 0);
        $taskIdleWorkerNum = (int)($stats['task_idle_worker_num'] ?? $stats['task_workers_idle'] ?? 0);

        return [
            'source' => $source,
            'worker_num' => $workerNum,
            'idle_worker_num' => $idleWorkerNum,
            'worker_busy' => max(0, $workerNum - $idleWorkerNum),
            'task_worker_num' => $taskWorkerNum,
            'task_idle_worker_num' => $taskIdleWorkerNum,
            'task_worker_busy' => max(0, $taskWorkerNum - $taskIdleWorkerNum),
            'tasking_num' => (int)($stats['tasking_num'] ?? 0),
            'connection_num' => (int)($stats['connection_num'] ?? $stats['connections_active'] ?? 0),
            'request_count' => (int)($stats['request_count'] ?? $stats['requests_total'] ?? 0),
            'start_time' => (int)($stats['start_time'] ?? 0),
            'error' => $stats['error'] ?? null,
        ];
    }

    private function renderHtml(array $payload): string
    {
        $jsonUrl = request()->url() . '?' . http_build_query(array_filter([
            'json' => 1,
            'date' => $payload['snapshot']['date'] ?? null,
        ]));
        $resetUrl = route('swoole_task_monitor.reset');
        $payloadJson = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
        );
        $resetAlert = request()->boolean('reset')
            ? '<div class="alert alert-success swoole-task-alert">Swoole Task监控数据已重置。</div>'
            : '';

        return <<<HTML
<style>
    .swoole-task-toolbar { display:flex; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:12px; }
    .swoole-task-alert { margin-bottom:12px; }
    .swoole-task-muted { color:#667085; }
    .swoole-task-grid { display:grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap:10px; margin-bottom:12px; }
    .swoole-task-card { background:#fff; border:1px solid #d8dde6; border-radius:4px; padding:10px 12px; min-height:72px; }
    .swoole-task-card-label { color:#667085; font-size:12px; margin-bottom:4px; }
    .swoole-task-card-value { font-size:22px; line-height:1.2; font-weight:600; color:#1f2937; }
    .swoole-task-panel { background:#fff; border:1px solid #d8dde6; border-radius:4px; padding:12px 14px; margin-bottom:12px; }
    .swoole-task-panel h3 { margin:0 0 10px; font-size:16px; font-weight:600; }
    .swoole-task-table { width:100%; border-collapse:collapse; }
    .swoole-task-table th, .swoole-task-table td { border-bottom:1px solid #edf0f5; padding:7px 8px; text-align:left; vertical-align:top; white-space:nowrap; }
    .swoole-task-table th { background:#fafbfc; color:#5f6b7a; font-weight:600; }
    .swoole-task-table .task-class { white-space:normal; min-width:280px; }
    .swoole-task-bad { color:#b42318; font-weight:600; }
    .swoole-task-warn { color:#b54708; font-weight:600; }
    .swoole-task-good { color:#027a48; font-weight:600; }
    .swoole-task-empty { color:#8a94a6; padding:8px 0; }
    .swoole-task-samples { display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap:12px; }
    .swoole-task-code { font-family:Menlo, Consolas, monospace; font-size:12px; word-break:break-all; white-space:normal; }
    @media (max-width:1200px) { .swoole-task-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } .swoole-task-samples { grid-template-columns:1fr; } }
    @media (max-width:700px) { .swoole-task-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>

{$resetAlert}

<div class="swoole-task-toolbar">
    <span class="label label-info">日期 <span id="swoole-task-date"></span></span>
    <span class="swoole-task-muted">更新时间: <span id="swoole-task-generated-at"></span></span>
    <a class="btn btn-xs btn-default" href="{$this->escape($jsonUrl)}">JSON</a>
    <form method="POST" action="{$this->escape($resetUrl)}" style="display:inline" onsubmit="return confirm('确认重置当天 Swoole Task 监控数据?')">
        {$this->csrfField()}
        <button type="submit" class="btn btn-xs btn-danger">重置当天数据</button>
    </form>
</div>

<div class="swoole-task-grid" id="swoole-task-kpis"></div>

<div class="swoole-task-panel">
    <h3>Swoole Runtime</h3>
    <div id="swoole-task-runtime"></div>
</div>

<div class="swoole-task-panel">
    <h3>Task Classes</h3>
    <div class="table-responsive">
        <table class="swoole-task-table">
            <thead>
                <tr>
                    <th class="task-class">Task</th>
                    <th>提交</th>
                    <th>投递成功</th>
                    <th>投递失败</th>
                    <th>丢弃</th>
                    <th>直接处理</th>
                    <th>待启动</th>
                    <th>运行中</th>
                    <th>完成</th>
                    <th>失败</th>
                    <th>慢任务</th>
                    <th>平均耗时</th>
                    <th>最大耗时</th>
                    <th>平均等待</th>
                    <th>最大等待</th>
                    <th>最大包</th>
                </tr>
            </thead>
            <tbody id="swoole-task-rows"></tbody>
        </table>
    </div>
</div>

<div class="swoole-task-samples">
    <div class="swoole-task-panel">
        <h3>最近异常</h3>
        <div id="swoole-task-errors"></div>
    </div>
    <div class="swoole-task-panel">
        <h3>最近慢任务</h3>
        <div id="swoole-task-slow"></div>
    </div>
    <div class="swoole-task-panel">
        <h3>最近丢弃/直接处理</h3>
        <div id="swoole-task-drops"></div>
    </div>
</div>

<script>
(function () {
    var payload = {$payloadJson};
    var jsonUrl = {$this->jsonString($jsonUrl)};

    function text(value) {
        if (value === null || value === undefined || value === '') {
            return '-';
        }
        return String(value);
    }

    function escapeHtml(value) {
        return text(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function number(value) {
        value = parseInt(value || 0, 10);
        return isNaN(value) ? 0 : value;
    }

    function ms(value) {
        value = number(value);
        if (value >= 1000) {
            return (value / 1000).toFixed(2) + ' s';
        }
        return value + ' ms';
    }

    function bytes(value) {
        value = number(value);
        var units = ['B', 'KB', 'MB', 'GB'];
        var idx = 0;
        var size = value;
        while (size >= 1024 && idx < units.length - 1) {
            size = size / 1024;
            idx++;
        }
        return idx === 0 ? size + ' ' + units[idx] : size.toFixed(2) + ' ' + units[idx];
    }

    function kpi(label, value, cssClass) {
        return '<div class="swoole-task-card">'
            + '<div class="swoole-task-card-label">' + escapeHtml(label) + '</div>'
            + '<div class="swoole-task-card-value ' + (cssClass || '') + '">' + escapeHtml(value) + '</div>'
            + '</div>';
    }

    function renderKpis(summary) {
        document.getElementById('swoole-task-kpis').innerHTML =
            kpi('提交', number(summary.submitted), '') +
            kpi('投递成功', number(summary.delivered), 'swoole-task-good') +
            kpi('投递失败', number(summary.deliver_failed), number(summary.deliver_failed) > 0 ? 'swoole-task-bad' : '') +
            kpi('丢弃', number(summary.dropped), number(summary.dropped) > 0 ? 'swoole-task-warn' : '') +
            kpi('待启动', number(summary.pending), number(summary.pending) > 0 ? 'swoole-task-warn' : '') +
            kpi('运行中', number(summary.running), '');
    }

    function renderRuntime(runtime) {
        var error = runtime.error ? '<tr><th>error</th><td class="swoole-task-bad">' + escapeHtml(runtime.error) + '</td></tr>' : '';
        document.getElementById('swoole-task-runtime').innerHTML =
            '<table class="swoole-task-table"><tbody>'
            + '<tr><th>source</th><td>' + escapeHtml(runtime.source) + '</td></tr>'
            + '<tr><th>worker_busy</th><td>' + number(runtime.worker_busy) + ' / ' + number(runtime.worker_num) + '</td></tr>'
            + '<tr><th>task_worker_busy</th><td>' + number(runtime.task_worker_busy) + ' / ' + number(runtime.task_worker_num) + '</td></tr>'
            + '<tr><th>tasking_num</th><td>' + number(runtime.tasking_num) + '</td></tr>'
            + '<tr><th>connection_num</th><td>' + number(runtime.connection_num) + '</td></tr>'
            + '<tr><th>request_count</th><td>' + number(runtime.request_count) + '</td></tr>'
            + error
            + '</tbody></table>';
    }

    function renderRows(rows) {
        if (!rows || rows.length === 0) {
            document.getElementById('swoole-task-rows').innerHTML = '<tr><td colspan="16" class="swoole-task-empty">暂无数据。等待 Swoole Task 投递或执行后会自动出现。</td></tr>';
            return;
        }

        document.getElementById('swoole-task-rows').innerHTML = rows.map(function (row) {
            return '<tr>'
                + '<td class="task-class"><div>' + escapeHtml(row.short_name) + '</div><div class="swoole-task-code swoole-task-muted">' + escapeHtml(row.task_class) + '</div></td>'
                + '<td>' + number(row.submitted) + '</td>'
                + '<td>' + number(row.delivered) + '</td>'
                + '<td class="' + (number(row.deliver_failed) > 0 ? 'swoole-task-bad' : '') + '">' + number(row.deliver_failed) + '</td>'
                + '<td class="' + (number(row.dropped) > 0 ? 'swoole-task-warn' : '') + '">' + number(row.dropped) + '</td>'
                + '<td>' + number(row.direct_handled) + '</td>'
                + '<td class="' + (number(row.pending) > 0 ? 'swoole-task-warn' : '') + '">' + number(row.pending) + '</td>'
                + '<td>' + number(row.running) + '</td>'
                + '<td>' + number(row.finished) + '</td>'
                + '<td class="' + (number(row.failed) > 0 ? 'swoole-task-bad' : '') + '">' + number(row.failed) + '</td>'
                + '<td class="' + (number(row.slow_count) > 0 ? 'swoole-task-warn' : '') + '">' + number(row.slow_count) + '</td>'
                + '<td>' + ms(row.duration_avg_ms) + '</td>'
                + '<td>' + ms(row.duration_max_ms) + '</td>'
                + '<td>' + ms(row.wait_avg_ms) + '</td>'
                + '<td>' + ms(row.wait_max_ms) + '</td>'
                + '<td>' + bytes(Math.max(number(row.payload_bytes_max), number(row.payload_bytes_max_runtime))) + '</td>'
                + '</tr>';
        }).join('');
    }

    function renderSamples(id, rows, kind) {
        if (!rows || rows.length === 0) {
            document.getElementById(id).innerHTML = '<div class="swoole-task-empty">暂无数据</div>';
            return;
        }

        document.getElementById(id).innerHTML = '<table class="swoole-task-table"><thead><tr><th>时间</th><th>Task</th><th>信息</th></tr></thead><tbody>'
            + rows.slice(0, 10).map(function (row) {
                var info = kind === 'slow'
                    ? ms(row.duration_ms) + ' / ' + bytes(row.payload_bytes)
                    : (row.reason || row.stage || '-');
                return '<tr>'
                    + '<td>' + escapeHtml(row.created_at) + '</td>'
                    + '<td><div>' + escapeHtml(shortName(row.task_class)) + '</div></td>'
                    + '<td class="swoole-task-code">' + escapeHtml(info) + '</td>'
                    + '</tr>';
            }).join('')
            + '</tbody></table>';
    }

    function shortName(value) {
        value = text(value);
        var idx = value.lastIndexOf('\\\\');
        return idx >= 0 ? value.substring(idx + 1) : value;
    }

    function render(nextPayload) {
        payload = nextPayload || payload;
        var snapshot = payload.snapshot || {};
        var summary = snapshot.summary || {};
        document.getElementById('swoole-task-date').textContent = snapshot.date || '-';
        document.getElementById('swoole-task-generated-at').textContent = payload.generated_at || '-';
        renderKpis(summary);
        renderRuntime(payload.runtime || {});
        renderRows(snapshot.rows || []);
        renderSamples('swoole-task-errors', snapshot.recent_errors || [], 'error');
        renderSamples('swoole-task-slow', snapshot.recent_slow || [], 'slow');
        renderSamples('swoole-task-drops', (snapshot.recent_drops || []).concat(snapshot.recent_direct || []), 'drop');
    }

    render(payload);
    setInterval(function () {
        fetch(jsonUrl, { headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(render)
            .catch(function () {});
    }, 5000);
})();
</script>
HTML;
    }

    private function csrfField(): string
    {
        return csrf_field();
    }

    private function jsonString(string $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
