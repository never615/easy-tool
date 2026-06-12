<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Routing\Controller;
use Mallto\Tool\Domain\NewConfig\NewConfigEffectiveEnv;

class NewConfigPublishController extends Controller
{
    public function index(NewConfigEffectiveEnv $effectiveEnv)
    {
        $snapshot = $effectiveEnv->snapshot();

        return Admin::content(function (Content $content) use ($snapshot) {
            $content->header('发布与重启');
            $content->description('配置中心 / 发布与重启');
            $content->body($this->renderHtml($snapshot));
        });
    }

    private function renderHtml(array $snapshot): string
    {
        $reloadButton = $this->reloadButton();
        $summary = $this->renderSummary($snapshot);
        $errors = $this->renderErrors($snapshot['errors'] ?? []);
        $rows = $this->renderRows($snapshot['rows'] ?? []);

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
            点击后会发布配置中心 env、强制刷新 Laravel config cache，并广播配置版本触发 LaravelS 实例重启；同时会请求 Horizon master 自终止后由进程管理器拉起。<br>
            该操作服务端 30 秒内只允许执行一次，避免连续点击导致重启重入。保存普通配置不会自动重启，需要所有新进程立即生效时再执行这里的发布与重启。
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

    private function reloadButton(): string
    {
        $url = route('new_configs.reload');
        $csrf = csrf_field();

        return <<<HTML
<form method="POST" action="{$this->escape($url)}" style="display:inline-block;" onsubmit="if (!confirm('确认发布配置中心 env、刷新 config cache，并广播配置版本触发各实例重启？')) { return false; } var btn=this.querySelector('button[type=submit]'); if (btn) { btn.disabled=true; btn.innerHTML='<i class=&quot;fa fa-refresh&quot;></i> 发布中...'; } return true;">
    {$csrf}
    <button type="submit" class="btn btn-warning"><i class="fa fa-refresh"></i> 发布并重启</button>
</form>
HTML;
    }

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

    private function renderValue($value): string
    {
        if ($value === null) {
            return '<span class="text-muted">-</span>';
        }

        return $this->escape((string)$value);
    }

    private function sourceClass(string $source): string
    {
        return match ($source) {
            'config_center' => 'label-success',
            'process_env' => 'label-info',
            default => 'label-default',
        };
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
