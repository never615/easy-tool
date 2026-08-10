<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Routing\Controller;
use Mallto\Tool\Domain\NewConfig\NewConfigEffectiveEnv;

/**
 * NewConfigPublishController：处理 new config publish controller 相关的 HTTP 请求。
 * 控制器负责参数和响应边界，领域规则交由服务或模型处理。
 */
class NewConfigPublishController extends Controller
{
    /**
     * 处理「index」相关的内部协作步骤。
     */
    public function index(NewConfigEffectiveEnv $effectiveEnv)
    {
        $snapshot = $effectiveEnv->snapshot();

        return Admin::content(function (Content $content) use ($snapshot) {
            $content->header('发布启动配置');
            $content->description('配置中心 / 发布启动配置');
            $content->body($this->renderHtml($snapshot));
        });
    }

    /**
     * 处理「renderHtml」相关的内部协作步骤。
     */
    private function renderHtml(array $snapshot): string
    {
        $reloadButton = $this->reloadButton();
        $summary = $this->renderSummary($snapshot);
        $errors = $this->renderErrors($snapshot['errors'] ?? []);
        $rows = $this->renderRows($snapshot['rows'] ?? []);
        $restartDescription = (bool)config('new_config.restart.enabled', true)
            ? '发布后会刷新 Laravel config cache，并调度 LaravelS/Horizon 重启。'
            : '重启功能已关闭；仍会发布启动配置并刷新 Laravel config cache，不会递增配置世代或调度重启。';

        return <<<HTML
<style>
    .new-config-publish-panel { background:#fff; border:1px solid #d8dde6; border-radius:4px; padding:14px 16px; margin-bottom:14px; }
    .new-config-publish-actions { display:flex; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:12px; }
    .new-config-publish-help { color:#667085; font-size:13px; line-height:1.7; margin:0; }
    .new-config-publish-summary { color:#667085; font-size:12px; margin-bottom:10px; }
    .new-config-env-table { width:100%; border-collapse:collapse; font-size:12px; }
    .new-config-env-table th, .new-config-env-table td { border-bottom:1px solid #edf0f5; padding:7px 8px; vertical-align:top; text-align:left; }
    .new-config-env-table th { background:#fafbfc; color:#5f6b7a; font-weight:600; white-space:nowrap; }
    .new-config-env-table .env-key { font-family:Menlo, Consolas, monospace; font-weight:600; white-space:nowrap; }
    .new-config-env-table .env-value { font-family:Menlo, Consolas, monospace; max-width:360px; word-break:break-all; white-space:normal; }
    .new-config-env-source { white-space:nowrap; }
    .new-config-env-empty { color:#8a94a6; padding:14px 0; }
    .new-config-env-error { margin:8px 0; }
</style>

<div class="new-config-publish-panel">
    <div class="new-config-publish-actions">
        {$reloadButton}
        <p class="new-config-publish-help">
            此处仅发布配置中心生成的启动配置，不会修改真实 .env。{$restartDescription}<br>
            普通业务配置保存后通过运行缓存生效，不需要在此发布。
        </p>
    </div>
</div>

<div class="new-config-publish-panel">
    <h4 style="margin-top:0;">生效 Env 内容</h4>
    {$summary}
    {$errors}
    <div class="table-responsive">
        <table class="new-config-env-table">
            <thead>
                <tr>
                    <th>Key</th>
                    <th>最终值</th>
                    <th>来源</th>
                    <th>.env 文件</th>
                    <th>当前进程 env</th>
                    <th>配置中心</th>
                </tr>
            </thead>
            <tbody>
                {$rows}
            </tbody>
        </table>
    </div>
</div>
HTML;
    }

    /**
     * 处理「reloadButton」相关的内部协作步骤。
     */
    private function reloadButton(): string
    {
        $url = route('new_configs.reload');
        $csrf = csrf_field();
        $confirm = (bool)config('new_config.restart.enabled', true)
            ? '确认发布启动配置、刷新 config cache，并广播配置版本触发各实例重启？'
            : '确认发布启动配置并刷新 config cache？重启功能已关闭，本次不会调度重启。';

        return <<<HTML
<form method="POST" action="{$this->escape($url)}" style="display:inline-block;" onsubmit="if (!confirm('{$this->escape($confirm)}')) { return false; } var btn=this.querySelector('button[type=submit]'); if (btn) { btn.disabled=true; btn.innerHTML='<i class=&quot;fa fa-refresh&quot;></i> 发布中...'; } return true;">
    {$csrf}
    <button type="submit" class="btn btn-warning"><i class="fa fa-refresh"></i> 发布启动配置</button>
</form>
HTML;
    }

    /**
     * 处理「renderSummary」相关的内部协作步骤。
     */
    private function renderSummary(array $snapshot): string
    {
        $counts = $snapshot['counts'] ?? [];
        $text = '更新时间: ' . ($snapshot['generated_at'] ?? '-')
            . ' / 总数: ' . (int)($counts['total'] ?? 0)
            . ' / 配置中心: ' . (int)($counts['config_center'] ?? 0)
            . ' / 当前进程 env: ' . (int)($counts['process_env'] ?? 0)
            . ' / .env: ' . (int)($counts['dotenv'] ?? 0);

        return '<div class="new-config-publish-summary">' . $this->escape($text) . '</div>';
    }

    /**
     * 处理「renderErrors」相关的内部协作步骤。
     */
    private function renderErrors(array $errors): string
    {
        if ($errors === []) {
            return '';
        }

        $html = '';
        foreach ($errors as $error) {
            $html .= '<div class="alert alert-warning new-config-env-error">'
                . $this->escape((string)($error['source'] ?? 'env'))
                . ': '
                . $this->escape((string)($error['message'] ?? ''))
                . '</div>';
        }

        return $html;
    }

    /**
     * 处理「renderRows」相关的内部协作步骤。
     */
    private function renderRows(array $rows): string
    {
        if ($rows === []) {
            return '<tr><td colspan="6" class="new-config-env-empty">暂无 Env 数据</td></tr>';
        }

        $html = '';
        foreach ($rows as $row) {
            $source = (string)($row['final_source'] ?? '');
            $sensitive = !empty($row['sensitive'])
                ? ' <span class="label label-warning">脱敏</span>'
                : '';

            $html .= '<tr>'
                . '<td class="env-key">' . $this->escape((string)$row['key']) . $sensitive . '</td>'
                . '<td class="env-value">' . $this->renderValue($row['final_value'] ?? null) . '</td>'
                . '<td class="new-config-env-source"><span class="label ' . $this->sourceClass($source) . '">' . $this->escape((string)($row['final_source_label'] ?? '-')) . '</span></td>'
                . '<td class="env-value">' . $this->renderValue($row['dotenv_value'] ?? null) . '</td>'
                . '<td class="env-value">' . $this->renderValue($row['process_value'] ?? null) . '</td>'
                . '<td class="env-value">' . $this->renderValue($row['config_center_value'] ?? null) . '</td>'
                . '</tr>';
        }

        return $html;
    }

    /**
     * 处理「renderValue」相关的内部协作步骤。
     */
    private function renderValue($value): string
    {
        if ($value === null) {
            return '<span class="text-muted">-</span>';
        }

        return $this->escape((string)$value);
    }

    /**
     * 处理「sourceClass」相关的内部协作步骤。
     */
    private function sourceClass(string $source): string
    {
        return match ($source) {
            'config_center' => 'label-success',
            'process_env' => 'label-info',
            default => 'label-default',
        };
    }

    /**
     * 处理「escape」相关的内部协作步骤。
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
