<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mallto\Tool\Domain\NewConfig\GlobalConfigDefinitions;
use Mallto\Tool\Domain\NewConfig\GlobalConfigModuleForm;

class ConfigModuleController extends Controller
{
    public function basic(GlobalConfigModuleForm $form)
    {
        return $this->index('basic', $form);
    }

    public function saveBasic(Request $request, GlobalConfigModuleForm $form)
    {
        return $this->save('basic', $request, $form);
    }

    public function sms(GlobalConfigModuleForm $form)
    {
        return $this->index('sms', $form);
    }

    public function saveSms(Request $request, GlobalConfigModuleForm $form)
    {
        return $this->save('sms', $request, $form);
    }

    public function locationAlgorithm(GlobalConfigModuleForm $form)
    {
        return $this->index('location_algorithm', $form);
    }

    public function saveLocationAlgorithm(Request $request, GlobalConfigModuleForm $form)
    {
        return $this->save('location_algorithm', $request, $form);
    }

    public function locationMaintenance(GlobalConfigModuleForm $form)
    {
        return $this->index('location_maintenance', $form);
    }

    public function saveLocationMaintenance(Request $request, GlobalConfigModuleForm $form)
    {
        return $this->save('location_maintenance', $request, $form);
    }

    public function beaconArea(GlobalConfigModuleForm $form)
    {
        return $this->index('beacon_area', $form);
    }

    public function saveBeaconArea(Request $request, GlobalConfigModuleForm $form)
    {
        return $this->save('beacon_area', $request, $form);
    }

    public function locationDebug(GlobalConfigModuleForm $form)
    {
        return $this->index('location_debug', $form);
    }

    public function saveLocationDebug(Request $request, GlobalConfigModuleForm $form)
    {
        return $this->save('location_debug', $request, $form);
    }

    private function index(string $module, GlobalConfigModuleForm $form)
    {
        $snapshot = $form->snapshot($module);

        return Admin::content(function (Content $content) use ($snapshot) {
            $content->header($snapshot['title']);
            $content->description('配置中心 / 模块表单');
            $content->body($this->renderHtml($snapshot));
        });
    }

    private function save(string $module, Request $request, GlobalConfigModuleForm $form)
    {
        $form->save($module, $request->only('values'));

        admin_toastr('配置已保存，运行期配置快照已发布；需要进程生效时请执行发布并重启。');

        return redirect()->route(GlobalConfigDefinitions::module($module)['route']);
    }

    private function renderHtml(array $snapshot): string
    {
        $action = route($snapshot['save_route']);
        $runtimeConfigUrl = route('new_configs.index');
        $globalConfigUrl = route('configs.index');
        $errors = $this->renderErrors();
        $rows = $this->renderRows($snapshot['rows'] ?? []);

        return <<<HTML
<style>
    .global-config-module-panel { background:#fff; border:1px solid #d8dde6; border-radius:4px; padding:14px 16px; margin-bottom:14px; }
    .global-config-module-table { width:100%; border-collapse:collapse; font-size:13px; }
    .global-config-module-table th, .global-config-module-table td { border-bottom:1px solid #edf0f5; padding:8px; vertical-align:top; text-align:left; }
    .global-config-module-table th { background:#fafbfc; color:#5f6b7a; font-weight:600; white-space:nowrap; }
    .global-config-module-table .config-name { min-width:180px; }
    .global-config-module-table .config-value { min-width:260px; }
    .global-config-module-table .config-meta { min-width:240px; color:#667085; font-size:12px; }
    .global-config-module-code { font-family:Menlo, Consolas, monospace; word-break:break-all; }
    .global-config-module-help { color:#8a94a6; font-size:12px; margin-top:4px; }
    .global-config-module-actions { display:flex; align-items:center; flex-wrap:wrap; gap:8px; margin-top:14px; }
    .global-config-module-error { color:#b42318; }
    .global-config-module-table textarea { min-height:110px; font-family:Menlo, Consolas, monospace; font-size:12px; }
</style>

{$errors}

<div class="global-config-module-panel">
    <form method="POST" action="{$this->escape($action)}">
        {$this->csrfField()}
        <div class="table-responsive">
            <table class="global-config-module-table">
                <thead>
                    <tr>
                        <th class="config-name">配置项</th>
                        <th class="config-value">当前值</th>
                        <th class="config-meta">发布信息</th>
                    </tr>
                </thead>
                <tbody>
                    {$rows}
                </tbody>
            </table>
        </div>
        <div class="global-config-module-actions">
            <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> 保存配置</button>
            <a class="btn btn-default" href="{$this->escape($globalConfigUrl)}"><i class="fa fa-list"></i> 全局配置列表</a>
            <a class="btn btn-default" href="{$this->escape($runtimeConfigUrl)}"><i class="fa fa-sliders"></i> 运行期配置</a>
        </div>
    </form>
</div>
HTML;
    }

    private function renderRows(array $rows): string
    {
        if ($rows === []) {
            return '<tr><td colspan="3" class="text-muted">暂无配置定义</td></tr>';
        }

        $html = '';
        foreach ($rows as $row) {
            $key = (string)$row['key'];
            $value = (string)$row['value'];
            $html .= '<tr>'
                . '<td class="config-name"><strong>' . $this->escape((string)$row['name']) . '</strong>'
                . '<div class="global-config-module-help global-config-module-code">' . $this->escape($key) . '</div>'
                . '<div class="global-config-module-help">' . $this->escape((string)$row['remark']) . '</div></td>'
                . '<td class="config-value">' . $this->renderInput($row, $key, $value)
                . '<div class="global-config-module-help">默认值: <span class="global-config-module-code">' . $this->escape((string)$row['default_value']) . '</span></div></td>'
                . '<td class="config-meta"><div>Env Key: <span class="global-config-module-code">' . $this->escape((string)$row['env_key']) . '</span></div>'
                . '<div>最近发布: ' . $this->escape((string)($row['last_published_at'] ?? '-')) . '</div>'
                . '<div class="global-config-module-error">发布错误: ' . $this->escape((string)($row['last_publish_error'] ?: '-')) . '</div></td>'
                . '</tr>';
        }

        return $html;
    }

    private function renderInput(array $row, string $key, string $value): string
    {
        $name = 'values[' . $this->escape($key) . ']';
        $type = (string)($row['type'] ?? 'string');

        if ($type === 'boolean') {
            return '<select class="form-control input-sm" name="' . $name . '">'
                . $this->option('0', '关闭', $value)
                . $this->option('1', '开启', $value)
                . '</select>';
        }

        if ($type === 'integer') {
            return '<input class="form-control input-sm" type="number" step="1" name="' . $name . '" value="' . $this->escape($value) . '">';
        }

        if ($type === 'float') {
            return '<input class="form-control input-sm" type="number" step="0.001" name="' . $name . '" value="' . $this->escape($value) . '">';
        }

        if (($row['ui'] ?? '') === 'textarea') {
            return '<textarea class="form-control" name="' . $name . '">' . $this->escape($value) . '</textarea>';
        }

        return '<input class="form-control input-sm" type="text" name="' . $name . '" value="' . $this->escape($value) . '">';
    }

    private function option(string $value, string $label, string $current): string
    {
        $selected = $value === $current ? ' selected' : '';

        return '<option value="' . $this->escape($value) . '"' . $selected . '>' . $this->escape($label) . '</option>';
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
