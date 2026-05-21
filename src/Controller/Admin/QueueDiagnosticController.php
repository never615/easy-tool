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
            'settings' => $config->currentSettings(),
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

    public function saveSettings(QueueDiagnosticConfig $config)
    {
        $config->saveSettings(request()->all());

        return redirect()->route('queue_diagnostics.index', [ 'saved' => 1 ]);
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
        $savedAlert = request()->boolean('saved')
            ? '<div class="alert alert-success queue-diag-alert">队列诊断配置已保存，下一次队列事件或 snapshot 会读取新配置。</div>'
            : '';
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
    .queue-diag-alert { margin-bottom: 14px; }
    .queue-diag-form-table input[type="text"], .queue-diag-form-table input[type="number"] { max-width: 360px; }
    .queue-diag-help { color: #8a94a6; font-size: 12px; margin-top: 4px; }
    .queue-diag-form-actions { margin-top: 12px; }
    .queue-diag-empty { color: #8a94a6; padding: 8px 0; }
    @media (max-width: 900px) { .queue-diag-grid { grid-template-columns: 1fr; } }
</style>

{$savedAlert}

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

<div class="queue-diag-panel">
    <h3>配置管理</h3>
    {$this->renderSettingsForm($payload['settings'] ?? [])}
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

    private function renderSettingsForm(array $settings): string
    {
        if (empty($settings)) {
            return '<div class="queue-diag-empty">暂无配置定义</div>';
        }

        $saveUrl = route('queue_diagnostics.settings');
        $html = '<form method="POST" action="' . $this->escape($saveUrl) . '">';
        $html .= csrf_field();
        $html .= '<table class="queue-diag-table queue-diag-form-table"><thead><tr><th>配置项</th><th>当前值</th><th>说明</th></tr></thead><tbody>';

        foreach ($settings as $setting) {
            $key = (string)($setting['key'] ?? '');
            $label = (string)($setting['label'] ?? $key);
            $type = (string)($setting['type'] ?? 'string');
            $value = (string)($setting['value'] ?? '');
            $remark = (string)($setting['remark'] ?? '');
            $default = (string)($setting['default'] ?? '');
            $source = !empty($setting['is_default'])
                ? '<span class="label label-default">默认值</span>'
                : '<span class="label label-info">已覆盖</span>';

            $html .= '<tr>';
            $html .= '<th>' . $this->escape($label) . '<div class="queue-diag-help">' . $this->escape($key) . '</div></th>';
            $html .= '<td>' . $this->renderSettingInput($key, $type, $value, $setting) . '</td>';
            $html .= '<td>' . $this->escape($remark)
                . '<div class="queue-diag-help">默认值: ' . $this->escape($default) . ' ' . $source . '</div></td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '<div class="queue-diag-form-actions">'
            . '<button type="submit" class="btn btn-primary">保存配置</button> '
            . '<span class="queue-diag-help">配置保存在专用表中，不进入全局 configs 页面；等于默认值的项不会额外落库。</span>'
            . '</div>';

        return $html . '</form>';
    }

    private function renderSettingInput(string $key, string $type, string $value, array $setting): string
    {
        $escapedKey = $this->escape($key);

        if ($type === 'boolean') {
            $checked = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? ' checked' : '';

            return '<input type="hidden" name="' . $escapedKey . '" value="0">'
                . '<label style="font-weight:400">'
                . '<input type="checkbox" name="' . $escapedKey . '" value="1"' . $checked . '> 开启'
                . '</label>';
        }

        if ($type === 'integer') {
            $min = array_key_exists('min', $setting) ? ' min="' . $this->escape((string)$setting['min']) . '"' : '';
            $max = array_key_exists('max', $setting) ? ' max="' . $this->escape((string)$setting['max']) . '"' : '';

            return '<input class="form-control input-sm" type="number" name="' . $escapedKey . '" value="'
                . $this->escape($value) . '"' . $min . $max . '>';
        }

        return '<input class="form-control input-sm" type="text" name="' . $escapedKey . '" value="'
            . $this->escape($value) . '">';
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
