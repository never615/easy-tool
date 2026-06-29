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

    public function showModule(string $module, GlobalConfigModuleForm $form)
    {
        return $this->index($module, $form);
    }

    public function saveModule(string $module, Request $request, GlobalConfigModuleForm $form)
    {
        return $this->save($module, $request, $form);
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

        admin_toastr('配置已保存，运行期配置快照已发布；如本次修改涉及需重启配置并需要立即生效，请发布并重启 LaravelS/Horizon。');

        return redirect()->route(GlobalConfigDefinitions::module($module)['route']);
    }

    private function renderHtml(array $snapshot): string
    {
        $action = route($snapshot['save_route']);
        $runtimeConfigUrl = route('new_configs.index');
        $globalConfigUrl = route('configs.index');
        $errors = $this->renderErrors();
        $snapshotRows = $snapshot['rows'] ?? [];
        $hasReloadRequiredRows = $this->hasReloadRequiredRows($snapshotRows);
        $reloadNotice = $this->renderReloadNotice($snapshotRows);
        $publishRestartAction = $hasReloadRequiredRows ? $this->publishRestartAction() : '';
        $familyFilters = $this->renderFamilyFilters($snapshotRows);
        $filterScript = $familyFilters === '' ? '' : $this->renderFamilyFilterScript();
        $rows = $this->renderRows($snapshotRows);

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
    .global-config-family-filters { display:flex; align-items:center; flex-wrap:wrap; gap:7px; margin-bottom:12px; }
    .global-config-family-filter { border-radius:3px; }
    .global-config-family-filter.active { background:#3c8dbc; border-color:#367fa9; color:#fff; box-shadow:none; }
    .global-config-family-filter.active .badge { background:rgba(255,255,255,.92); color:#3c8dbc; }
    .global-config-family-badge { display:inline-block; margin-left:6px; font-weight:400; vertical-align:middle; }
    .global-config-module-error { color:#b42318; }
    .global-config-module-table textarea { min-height:110px; font-family:Menlo, Consolas, monospace; font-size:12px; }
</style>

{$errors}

<div class="global-config-module-panel">
    {$reloadNotice}
    <form method="POST" action="{$this->escape($action)}">
        {$this->csrfField()}
        {$familyFilters}
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
            {$publishRestartAction}
            <a class="btn btn-default" href="{$this->escape($globalConfigUrl)}"><i class="fa fa-list"></i> 全局配置列表</a>
            <a class="btn btn-default" href="{$this->escape($runtimeConfigUrl)}"><i class="fa fa-sliders"></i> 运行期配置</a>
        </div>
    </form>
</div>
{$filterScript}
HTML;
    }

    private function hasReloadRequiredRows(array $rows): bool
    {
        foreach ($rows as $row) {
            if (!empty($row['requires_reload'])) {
                return true;
            }
        }

        return false;
    }

    private function renderReloadNotice(array $rows): string
    {
        if (!$this->hasReloadRequiredRows($rows)) {
            return '';
        }

        $publishRestartUrl = route('new_configs.publish_restart');

        return '<div class="alert alert-warning global-config-module-reload-notice">'
            . '<strong>修改并保存后，如需让 LaravelS/Horizon 立即读取新值，请发布并重启。</strong> '
            . '本页包含需重启后才会被长驻进程读取的新配置项；未修改配置无需操作。'
            . '保存只写入运行期配置快照并刷新 config cache。'
            . '如本次修改需要立即生效，再进入 <a href="' . $this->escape($publishRestartUrl) . '">发布与重启</a> 页面执行操作。'
            . '</div>';
    }

    private function publishRestartAction(): string
    {
        $publishRestartUrl = route('new_configs.publish_restart');

        return '<a class="btn btn-warning" href="' . $this->escape($publishRestartUrl) . '"><i class="fa fa-refresh"></i> 发布与重启</a>';
    }

    private function renderFamilyFilters(array $rows): string
    {
        $families = [];
        foreach ($rows as $row) {
            $familyKey = (string)($row['family_key'] ?? '');
            $familyLabel = (string)($row['family_label'] ?? '');
            if ($familyKey === '') {
                continue;
            }

            if (!isset($families[$familyKey])) {
                $families[$familyKey] = [
                    'label' => $familyLabel !== '' ? $familyLabel : $familyKey,
                    'count' => 0,
                ];
            }

            $families[$familyKey]['count']++;
        }

        if ($families === []) {
            return '';
        }

        $total = array_sum(array_column($families, 'count'));
        $html = '<div class="global-config-family-filters">'
            . '<button type="button" class="btn btn-primary btn-xs global-config-family-filter active" data-family-filter="all">'
            . '全部 <span class="badge">' . $this->escape((string)$total) . '</span></button>';

        foreach ($families as $familyKey => $family) {
            $html .= '<button type="button" class="btn btn-default btn-xs global-config-family-filter" data-family-filter="' . $this->escape($familyKey) . '">'
                . $this->escape((string)$family['label']) . ' <span class="badge">' . $this->escape((string)$family['count']) . '</span></button>';
        }

        return $html . '</div>';
    }

    private function renderFamilyFilterScript(): string
    {
        return <<<HTML
<script>
(function () {
    var buttons = document.querySelectorAll('.global-config-family-filter');
    var rows = document.querySelectorAll('.global-config-module-row');
    if (!buttons.length || !rows.length) {
        return;
    }

    buttons.forEach(function (button) {
        button.addEventListener('click', function () {
            var family = button.getAttribute('data-family-filter') || 'all';

            buttons.forEach(function (item) {
                item.classList.remove('active', 'btn-primary');
                item.classList.add('btn-default');
            });
            button.classList.add('active', 'btn-primary');
            button.classList.remove('btn-default');

            rows.forEach(function (row) {
                var rowFamily = row.getAttribute('data-family') || '';
                row.style.display = family === 'all' || rowFamily === family ? '' : 'none';
            });
        });
    });
})();
</script>
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
            $familyKey = (string)($row['family_key'] ?? '');
            $familyBadge = $this->renderFamilyBadge($row);
            $reloadBadge = $this->renderReloadBadge($row);

            $html .= '<tr class="global-config-module-row" data-family="' . $this->escape($familyKey) . '">'
                . '<td class="config-name"><strong>' . $this->escape((string)$row['name']) . '</strong>' . $familyBadge
                . '<div class="global-config-module-help global-config-module-code">' . $this->escape($key) . '</div>'
                . '<div class="global-config-module-help">' . $this->escape((string)$row['remark']) . '</div></td>'
                . '<td class="config-value">' . $this->renderInput($row, $key, $value)
                . '<div class="global-config-module-help">默认值: <span class="global-config-module-code">' . $this->escape((string)$row['default_value']) . '</span></div></td>'
                . '<td class="config-meta"><div>Env Key: <span class="global-config-module-code">' . $this->escape((string)$row['env_key']) . '</span></div>'
                . '<div>最近发布: ' . $this->escape((string)($row['last_published_at'] ?? '-')) . '</div>'
                . '<div class="global-config-module-error">发布错误: ' . $this->escape((string)($row['last_publish_error'] ?: '-')) . '</div>'
                . $reloadBadge . '</td>'
                . '</tr>';
        }

        return $html;
    }

    private function renderReloadBadge(array $row): string
    {
        if (empty($row['requires_reload'])) {
            return '';
        }

        return '<div class="text-warning"><i class="fa fa-refresh"></i> 修改后需发布并重启 LaravelS/Horizon 生效</div>';
    }

    private function renderFamilyBadge(array $row): string
    {
        $familyLabel = (string)($row['family_label'] ?? '');
        if ($familyLabel === '') {
            return '';
        }

        return '<span class="label label-default global-config-family-badge">' . $this->escape($familyLabel) . '</span>';
    }

    private function renderInput(array $row, string $key, string $value): string
    {
        $name = 'values[' . $this->escape($key) . ']';
        $type = (string)($row['type'] ?? 'string');
        $placeholder = $this->placeholderAttribute($row);

        if ($type === 'boolean') {
            return '<select class="form-control input-sm" name="' . $name . '">'
                . $this->option('0', '关闭', $value)
                . $this->option('1', '开启', $value)
                . '</select>';
        }

        if ($type === 'integer') {
            return '<input class="form-control input-sm" type="number" step="1" name="' . $name . '" value="' . $this->escape($value) . '"' . $placeholder . '>';
        }

        if ($type === 'float') {
            return '<input class="form-control input-sm" type="number" step="0.001" name="' . $name . '" value="' . $this->escape($value) . '"' . $placeholder . '>';
        }

        if (($row['ui'] ?? '') === 'textarea') {
            return '<textarea class="form-control" name="' . $name . '"' . $placeholder . '>' . $this->escape($value) . '</textarea>';
        }

        return '<input class="form-control input-sm" type="text" name="' . $name . '" value="' . $this->escape($value) . '"' . $placeholder . '>';
    }

    private function placeholderAttribute(array $row): string
    {
        $placeholder = (string)($row['placeholder'] ?? '');
        if ($placeholder === '') {
            return '';
        }

        return ' placeholder="' . $this->escape($placeholder) . '"';
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
