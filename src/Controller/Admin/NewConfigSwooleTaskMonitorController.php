<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mallto\Tool\Domain\NewConfig\SwooleTaskMonitorConfigForm;

class NewConfigSwooleTaskMonitorController extends Controller
{
    public function index(SwooleTaskMonitorConfigForm $configForm)
    {
        $snapshot = $configForm->snapshot();

        return Admin::content(function (Content $content) use ($snapshot) {
            $content->header('Swoole Task配置');
            $content->description('配置中心 / 模块表单');
            $content->body($this->renderHtml($snapshot));
        });
    }

    public function save(Request $request, SwooleTaskMonitorConfigForm $configForm)
    {
        $configForm->save($request->only([
            'enabled',
            'mode',
            'trace_sample_rate',
        ]));

        admin_toastr('Swoole Task 监控配置已保存，运行期 env 已发布；需要进程生效时请执行发布并重启。');

        return redirect()->route('new_configs.swoole_task_monitor');
    }

    private function renderHtml(array $snapshot): string
    {
        $action = route('new_configs.swoole_task_monitor.save');
        $runtimeConfigUrl = route('new_configs.index');
        $monitorUrl = route('swoole_task_monitor.index');
        $enabledValue = (string)old('enabled', $snapshot['enabled_value'] ?? '0');
        $mode = (string)old('mode', $snapshot['mode'] ?? 'summary');
        $sampleRate = (string)old('trace_sample_rate', $snapshot['trace_sample_rate'] ?? '0');
        $enabledOptions = $this->renderOptions([
            '1' => '开启',
            '0' => '关闭',
        ], $enabledValue);
        $modeOptions = $this->renderOptions(SwooleTaskMonitorConfigForm::modeOptions(), $mode);
        $errors = $this->renderErrors();
        $rows = $this->renderPublishRows($snapshot['rows'] ?? []);

        return <<<HTML
<style>
    .new-config-module-grid { display:grid; grid-template-columns:minmax(0, 1.1fr) minmax(360px, .9fr); gap:14px; align-items:start; }
    .new-config-module-panel { background:#fff; border:1px solid #d8dde6; border-radius:4px; padding:14px 16px; margin-bottom:14px; }
    .new-config-module-panel h3 { margin:0 0 12px; font-size:16px; font-weight:600; }
    .new-config-module-form .form-group { margin-bottom:14px; }
    .new-config-module-form label { color:#344054; font-weight:600; }
    .new-config-module-help { color:#8a94a6; font-size:12px; margin-top:4px; }
    .new-config-module-actions { display:flex; align-items:center; flex-wrap:wrap; gap:8px; margin-top:16px; }
    .new-config-module-table { width:100%; border-collapse:collapse; font-size:12px; }
    .new-config-module-table th, .new-config-module-table td { border-bottom:1px solid #edf0f5; padding:7px 8px; text-align:left; vertical-align:top; }
    .new-config-module-table th { background:#fafbfc; color:#5f6b7a; font-weight:600; white-space:nowrap; }
    .new-config-module-code { font-family:Menlo, Consolas, monospace; word-break:break-all; }
    .new-config-module-error { color:#b42318; }
    .new-config-module-empty { color:#8a94a6; padding:8px 0; }
    @media (max-width:1000px) { .new-config-module-grid { grid-template-columns:1fr; } }
</style>

{$errors}

<div class="new-config-module-grid">
    <div class="new-config-module-panel">
        <h3>配置表单</h3>
        <form class="new-config-module-form" method="POST" action="{$this->escape($action)}">
            {$this->csrfField()}
            <div class="form-group">
                <label for="new-config-swoole-task-enabled">监控总开关</label>
                <select id="new-config-swoole-task-enabled" class="form-control" name="enabled">
                    {$enabledOptions}
                </select>
                <div class="new-config-module-help">对应 SWOOLE_TASK_MONITOR_ENABLED。</div>
            </div>
            <div class="form-group">
                <label for="new-config-swoole-task-mode">监控模式</label>
                <select id="new-config-swoole-task-mode" class="form-control" name="mode">
                    {$modeOptions}
                </select>
                <div class="new-config-module-help">summary 记录聚合指标，trace 记录明细，off 关闭采集。</div>
            </div>
            <div class="form-group">
                <label for="new-config-swoole-task-trace-sample-rate">trace 采样率</label>
                <input id="new-config-swoole-task-trace-sample-rate" class="form-control" type="number" name="trace_sample_rate" value="{$this->escape($sampleRate)}" min="0" max="1" step="0.001">
                <div class="new-config-module-help">取值 0 到 1，例如 0.01 表示约 1% task 明细采样。</div>
            </div>
            <div class="new-config-module-actions">
                <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> 保存配置</button>
                <a class="btn btn-default" href="{$this->escape($monitorUrl)}"><i class="fa fa-line-chart"></i> 监控页面</a>
                <a class="btn btn-default" href="{$this->escape($runtimeConfigUrl)}"><i class="fa fa-sliders"></i> 运行期配置</a>
            </div>
        </form>
    </div>
    <div class="new-config-module-panel">
        <h3>发布状态</h3>
        {$rows}
    </div>
</div>
HTML;
    }

    private function renderOptions(array $options, string $current): string
    {
        $html = '';
        foreach ($options as $value => $label) {
            $selected = (string)$value === $current ? ' selected' : '';
            $html .= '<option value="' . $this->escape((string)$value) . '"' . $selected . '>'
                . $this->escape((string)$label) . '</option>';
        }

        return $html;
    }

    private function renderPublishRows(array $rows): string
    {
        if ($rows === []) {
            return '<div class="new-config-module-empty">暂无配置定义</div>';
        }

        $html = '<table class="new-config-module-table"><thead><tr>'
            . '<th>配置项</th><th>发布值</th><th>Env Key</th><th>最近发布</th><th>发布错误</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $error = $row['last_publish_error'] ?? null;
            $html .= '<tr>'
                . '<td>' . $this->escape((string)($row['name'] ?? '-')) . '</td>'
                . '<td class="new-config-module-code">' . $this->escape((string)($row['value'] ?? '')) . '</td>'
                . '<td class="new-config-module-code">' . $this->escape((string)($row['env_key'] ?? '-')) . '</td>'
                . '<td>' . $this->escape((string)($row['last_published_at'] ?? '-')) . '</td>'
                . '<td class="new-config-module-error">' . $this->escape($error ? (string)$error : '-') . '</td>'
                . '</tr>';
        }

        return $html . '</tbody></table>';
    }

    private function renderErrors(): string
    {
        $errors = session('errors');
        if (!$errors || !$errors->any()) {
            return '';
        }

        $html = '<div class="alert alert-danger"><ul style="margin-bottom:0">';
        foreach ($errors->all() as $error) {
            $html .= '<li>' . $this->escape((string)$error) . '</li>';
        }

        return $html . '</ul></div>';
    }

    private function csrfField(): string
    {
        return csrf_field();
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
