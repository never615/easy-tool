<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mallto\Tool\Domain\NewConfig\GlobalConfigDefinitions;
use Mallto\Tool\Domain\NewConfig\GlobalConfigModuleForm;

/**
 * ConfigModuleController：处理 config module controller 相关的 HTTP 请求。
 * 控制器负责参数和响应边界，领域规则交由服务或模型处理。
 */
class ConfigModuleController extends Controller
{
    /**
     * 处理「basic」相关的内部协作步骤。
     */
    public function basic(GlobalConfigModuleForm $form)
    {
        return $this->index('basic', $form);
    }

    /**
     * 处理「saveBasic」相关的内部协作步骤。
     */
    public function saveBasic(Request $request, GlobalConfigModuleForm $form)
    {
        return $this->save('basic', $request, $form);
    }

    /**
     * 处理「sms」相关的内部协作步骤。
     */
    public function sms(GlobalConfigModuleForm $form)
    {
        return $this->index('sms', $form);
    }

    /**
     * 处理「saveSms」相关的内部协作步骤。
     */
    public function saveSms(Request $request, GlobalConfigModuleForm $form)
    {
        return $this->save('sms', $request, $form);
    }

    /**
     * 处理「showModule」相关的内部协作步骤。
     */
    public function showModule(string $module, GlobalConfigModuleForm $form)
    {
        return $this->index($module, $form);
    }

    /**
     * 处理「saveModule」相关的内部协作步骤。
     */
    public function saveModule(string $module, Request $request, GlobalConfigModuleForm $form)
    {
        return $this->save($module, $request, $form);
    }

    /**
     * 处理「index」相关的内部协作步骤。
     */
    private function index(string $module, GlobalConfigModuleForm $form)
    {
        $snapshot = $form->snapshot($module);

        return Admin::content(function (Content $content) use ($snapshot) {
            $content->header($snapshot['title']);
            $content->description('配置中心 / 模块表单');
            $content->body($this->renderHtml($snapshot));
        });
    }

    /**
     * 处理「save」相关的内部协作步骤。
     */
    private function save(string $module, Request $request, GlobalConfigModuleForm $form)
    {
        $form->save($module, $request->only('overrides'));

        admin_toastr('配置已保存，将通过运行缓存生效。');

        return redirect()->route(GlobalConfigDefinitions::module($module)['route']);
    }

    /**
     * 处理「renderHtml」相关的内部协作步骤。
     */
    private function renderHtml(array $snapshot): string
    {
        $action = route($snapshot['save_route']);
        $runtimeConfigUrl = route('new_configs.index');
        $globalConfigUrl = route('configs.index');
        $errors = $this->renderErrors();
        $snapshotRows = $snapshot['rows'] ?? [];
        $reloadNotice = '';
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
                        <th class="config-value">配置值与来源</th>
                        <th class="config-meta">生效信息</th>
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
{$filterScript}
HTML;
    }

    /**
     * 处理「renderFamilyFilters」相关的内部协作步骤。
     */
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

    /**
     * 处理「renderFamilyFilterScript」相关的内部协作步骤。
     */
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

    /**
     * 处理「renderRows」相关的内部协作步骤。
     */
    private function renderRows(array $rows): string
    {
        if ($rows === []) {
            return '<tr><td colspan="3" class="text-muted">暂无配置定义</td></tr>';
        }

        $html = '';
        foreach ($rows as $row) {
            $key = (string)$row['key'];
            $overrideValue = $row['override_value'] ?? $row['baseline_value'] ?? '';
            $overrideValue = $overrideValue === null ? '' : (string)$overrideValue;
            $familyKey = (string)($row['family_key'] ?? '');
            $familyBadge = $this->renderFamilyBadge($row);
            $reloadBadge = $this->renderReloadBadge($row);
            $sourceLabel = $this->sourceLabel($row);
            $configBacked = (bool)($row['config_backed'] ?? false);
            $baselineValue = $this->displayValue($row, 'baseline_value');
            $databaseValue = $this->displayValue($row, 'override_value');
            $effectiveValue = $this->displayValue($row, 'effective_value');

            $html .= '<tr class="global-config-module-row" data-family="' . $this->escape($familyKey) . '">'
                . '<td class="config-name"><strong>' . $this->escape((string)$row['name']) . '</strong>' . $familyBadge
                . '<div class="global-config-module-help global-config-module-code">' . $this->escape($key) . '</div>'
                . '<div class="global-config-module-help">' . $this->escape((string)$row['remark']) . '</div></td>'
                . '<td class="config-value">' . $this->renderOverrideControl($row, $key, $overrideValue)
                . '<div class="global-config-module-help">' . ($configBacked ? '环境值' : '代码兜底值') . '：<span class="global-config-module-code">' . $baselineValue . '</span></div>'
                . '<div class="global-config-module-help">' . ($configBacked ? '数据库覆盖值' : '数据库值') . '：<span class="global-config-module-code">' . $databaseValue . '</span></div>'
                . '<div class="global-config-module-help"><strong>最终生效值：</strong><span class="global-config-module-code">' . $effectiveValue . '</span></div></td>'
                . '<td class="config-meta"><div>Env Key: <span class="global-config-module-code">' . $this->escape((string)$row['env_key']) . '</span></div>'
                . '<div>' . $sourceLabel . '</div>'
                . ($configBacked ? '<div>覆盖来源: <span class="global-config-module-code">' . $this->escape((string)($row['override_source'] ?? '-')) . '</span></div>' : '')
                . '<div>最近发布: ' . $this->escape((string)($row['last_published_at'] ?? '-')) . '</div>'
                . '<div class="global-config-module-error">发布错误: ' . $this->escape((string)($row['last_publish_error'] ?: '-')) . '</div>'
                . $reloadBadge . '</td>'
                . '</tr>';
        }

        return $html;
    }

    /**
     * 处理「renderReloadBadge」相关的内部协作步骤。
     */
    private function renderReloadBadge(array $row): string
    {
        return '<div class="text-success"><i class="fa fa-bolt"></i> 保存后通过运行缓存生效</div>';
    }

    /**
     * 处理「renderFamilyBadge」相关的内部协作步骤。
     */
    private function renderFamilyBadge(array $row): string
    {
        $familyLabel = (string)($row['family_label'] ?? '');
        if ($familyLabel === '') {
            return '';
        }

        return '<span class="label label-default global-config-family-badge">' . $this->escape($familyLabel) . '</span>';
    }

    /**
     * 处理「renderInput」相关的内部协作步骤。
     */
    private function renderInput(array $row, string $key, string $value): string
    {
        $name = 'overrides[' . $this->escape($key) . '][value]';
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

    private function renderOverrideControl(array $row, string $key, string $value): string
    {
        if (($row['override_policy'] ?? null) === 'system') {
            return '<span class="label label-warning">系统管理，不可在此修改</span>';
        }

        $escapedKey = $this->escape($key);
        $checked = ($row['override_enabled'] ?? false) ? ' checked' : '';

        if (!($row['config_backed'] ?? false)) {
            return '<div class="checkbox" style="margin-top:0">'
                . '<input type="hidden" name="overrides[' . $escapedKey . '][enabled]" value="0">'
                . '<label><input type="checkbox" name="overrides[' . $escapedKey . '][enabled]" value="1"' . $checked . '> 启用配置</label>'
                . '</div>'
                . $this->renderInput($row, $key, $value)
                . '<div class="global-config-module-help">该配置以数据库为主；停用后使用代码兜底值。</div>';
        }

        return '<div class="checkbox" style="margin-top:0">'
            . '<input type="hidden" name="overrides[' . $escapedKey . '][enabled]" value="0">'
            . '<label><input type="checkbox" name="overrides[' . $escapedKey . '][enabled]" value="1"' . $checked . '> 启用数据库覆盖</label>'
            . '</div>'
            . $this->renderInput($row, $key, $value)
            . '<div class="global-config-module-help">取消勾选并保存，即恢复使用当前环境 config 基线。</div>';
    }

    private function displayValue(array $row, string $field): string
    {
        if (($row['sensitive'] ?? false) === true) {
            return '<em>已脱敏</em>';
        }

        $value = $row[$field] ?? null;

        return $value === null ? '-' : $this->escape((string)$value);
    }

    private function sourceLabel(array $row): string
    {
        if (($row['effective_source'] ?? null) === 'database') {
            return '<span class="label label-primary">来源：数据库配置</span>';
        }

        if (($row['effective_source'] ?? null) === 'database_override') {
            return '<span class="label label-primary">来源：数据库显式覆盖</span>';
        }

        return '<span class="label label-default">来源：环境配置</span>';
    }

    /**
     * 处理「placeholderAttribute」相关的内部协作步骤。
     */
    private function placeholderAttribute(array $row): string
    {
        $placeholder = (string)($row['placeholder'] ?? '');
        if ($placeholder === '') {
            return '';
        }

        return ' placeholder="' . $this->escape($placeholder) . '"';
    }

    /**
     * 处理「option」相关的内部协作步骤。
     */
    private function option(string $value, string $label, string $current): string
    {
        $selected = $value === $current ? ' selected' : '';

        return '<option value="' . $this->escape($value) . '"' . $selected . '>' . $this->escape($label) . '</option>';
    }

    /**
     * 处理「renderErrors」相关的内部协作步骤。
     */
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

    /**
     * 处理「csrfField」相关的内部协作步骤。
     */
    private function csrfField(): string
    {
        return csrf_field();
    }

    /**
     * 处理「escape」相关的内部协作步骤。
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
