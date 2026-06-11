<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Illuminate\Support\Facades\Cache;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Tool\Data\NewConfig;
use Mallto\Tool\Domain\NewConfig\NewConfigBootstrapKeyGuard;
use Mallto\Tool\Domain\NewConfig\NewConfigEffectiveEnv;
use Mallto\Tool\Domain\NewConfig\NewConfigPublisher;

class NewConfigController extends AdminCommonController
{
    private const ENV_PREVIEW_MODAL_ID = 'new-config-env-preview-modal';
    private const RELOAD_THROTTLE_KEY = 'new_configs:manual_reload_throttle';
    private const RELOAD_THROTTLE_SECONDS = 30;

    protected function getHeaderTitle()
    {
        return '配置中心';
    }

    protected function getModel()
    {
        return NewConfig::class;
    }

    protected function gridOption(Grid $grid)
    {
        $grid->model()
            ->orderBy('group_key')
            ->orderBy('sort')
            ->orderBy('id');

        $grid->group_key('分组')->label();
        $grid->name('配置项');
        $grid->key('Key')->copyable();
        $grid->env_key('Env Key')->copyable();
        $grid->type('类型')->label();
        $grid->value('当前值')->limit(30)->editable(); //limit必须放在 editable 之前
        $grid->default_value('默认值')->limit(30);
        $grid->remark('说明')->limit(40);
        $grid->sort('排序')->editable();
        $grid->is_enabled('启用')->switchE();
        $grid->requires_reload('需重启')->switchE();
        $grid->last_published_at('发布时间');
        $grid->last_publish_error('发布错误')->limit(40);

        $grid->filter(function ($filter) {
            $filter->ilike('key', 'Key');
            $filter->ilike('env_key', 'Env Key');
            $filter->ilike('name', '配置项');
            $filter->equal('group_key', '分组');
        });

        $grid->tools(function ($tools) {
            $tools->append($this->reloadButton());
            $tools->append($this->envPreviewButton());
        });

        Admin::html($this->envPreviewModal());
        Admin::script($this->envPreviewScript(route('new_configs.env_preview')));
    }

    public function envPreview(NewConfigEffectiveEnv $effectiveEnv)
    {
        return response()->json($effectiveEnv->snapshot(), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function reload(NewConfigPublisher $publisher)
    {
        if (!Cache::add(self::RELOAD_THROTTLE_KEY, time(), self::RELOAD_THROTTLE_SECONDS)) {
            admin_toastr('重启操作过于频繁，请 30 秒后再试。', 'warning');

            return redirect()->route('new_configs.index');
        }

        $result = $publisher->publish(true, true);
        $restart = $result['restart'] ?? null;

        if ($restart === null) {
            admin_toastr('配置已发布；当前没有需要重启的已发布配置项，服务重启未执行。', 'warning');
        } elseif (is_array($restart) && ($restart['skipped'] ?? false)) {
            admin_toastr('配置已发布，但服务重启跳过：' . ($restart['reason'] ?? 'unknown'), 'warning');
        } else {
            admin_toastr('配置已发布，并已触发服务重启。');
        }

        return redirect()->route('new_configs.index');
    }

    protected function formOption(Form $form)
    {
        $form->text('group_key', '分组')->required();
        $form->text('key', 'Key')->required();
        $form->text('env_key', 'Env Key')
            ->help('导出为运行期环境变量名，例如 SWOOLE_TASK_MONITOR_ENABLED。留空则不导出。<br>' . NewConfigBootstrapKeyGuard::forbiddenHint());
        $form->text('name', '配置项')->required();
        $form->select('type', '类型')
            ->options([
                'boolean' => 'boolean',
                'string' => 'string',
                'integer' => 'integer',
                'float' => 'float',
                'select' => 'select',
                'json' => 'json',
            ])
            ->default('string')
            ->required();
        $form->textarea('value', '当前值')
            ->help('留空时使用默认值。boolean 建议填写 0/1；float 可填写 0.01。');
        $form->textarea('default_value', '默认值');
        $form->textarea('options', '选项')
            ->help('可选，JSON 或逗号分隔，用于说明可选值。例如 ["summary","trace","off"]。');
        $form->textarea('remark', '说明');
        $form->number('sort', '排序')->default(0);
        $form->switch('is_enabled', '启用')->default(1);
        $form->switch('requires_reload', '需重启')->default(1)
            ->help('开启时表示该配置需要服务重启后才会被所有新进程读取。保存配置只发布运行期 env 并刷新 config cache，不会自动重启；需要生效时请在列表页点击“手动重启”。');
        $form->display('last_published_at', '最近发布时间');
        $form->display('last_publish_error', '最近发布错误');
    }

    private function envPreviewButton(): string
    {
        return '<button type="button" class="btn btn-sm btn-default new-config-env-preview-button">'
            . '<i class="fa fa-eye"></i> 查看生效 Env</button>';
    }

    private function reloadButton(): string
    {
        $url = route('new_configs.reload');
        $csrf = csrf_field();

        return <<<HTML
<form method="POST" action="{$url}" style="display:inline-block;margin-right:6px;" onsubmit="if (!confirm('确认发布配置中心 env、刷新 config cache，并触发服务重启？')) { return false; } var btn=this.querySelector('button[type=submit]'); if (btn) { btn.disabled=true; btn.innerHTML='<i class=&quot;fa fa-refresh&quot;></i> 重启中...'; } return true;">
    {$csrf}
    <button type="submit" class="btn btn-sm btn-warning"><i class="fa fa-refresh"></i> 手动重启</button>
</form>
HTML;
    }

    private function envPreviewModal(): string
    {
        $id = self::ENV_PREVIEW_MODAL_ID;

        return <<<HTML
<style>
    .new-config-env-toolbar { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
    .new-config-env-search { width:260px; max-width:100%; }
    .new-config-env-meta { color:#667085; font-size:12px; }
    .new-config-env-table { width:100%; border-collapse:collapse; font-size:12px; }
    .new-config-env-table th, .new-config-env-table td { border-bottom:1px solid #edf0f5; padding:7px 8px; vertical-align:top; text-align:left; }
    .new-config-env-table th { background:#fafbfc; color:#5f6b7a; font-weight:600; white-space:nowrap; }
    .new-config-env-table .env-key { font-family:Menlo, Consolas, monospace; font-weight:600; white-space:nowrap; }
    .new-config-env-table .env-value { font-family:Menlo, Consolas, monospace; max-width:340px; word-break:break-all; white-space:normal; }
    .new-config-env-empty { color:#8a94a6; padding:14px 0; }
    .new-config-env-source { white-space:nowrap; }
    .new-config-env-error { margin:8px 0; }
</style>

<div class="modal fade" id="{$id}" tabindex="-1" role="dialog" aria-labelledby="{$id}-title">
    <div class="modal-dialog modal-xl" style="width:95%; max-width:1280px;" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="{$id}-title">生效 Env 预览</h4>
            </div>
            <div class="modal-body">
                <div class="new-config-env-toolbar">
                    <input type="text" class="form-control input-sm new-config-env-search" placeholder="搜索 Key">
                    <button type="button" class="btn btn-xs btn-default new-config-env-refresh">刷新</button>
                    <span class="new-config-env-meta"></span>
                </div>
                <div class="new-config-env-errors"></div>
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
                        <tbody class="new-config-env-rows">
                            <tr><td colspan="6" class="new-config-env-empty">加载中...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
HTML;
    }

    private function envPreviewScript(string $url): string
    {
        $modalId = self::ENV_PREVIEW_MODAL_ID;
        $jsonUrl = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        return <<<JS
(function () {
    var modal = $('#{$modalId}');
    var rows = [];
    var url = {$jsonUrl};

    function escapeHtml(value) {
        if (value === null || value === undefined) {
            return '<span class="text-muted">-</span>';
        }

        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function sourceClass(source) {
        if (source === 'config_center') {
            return 'label-success';
        }
        if (source === 'process_env') {
            return 'label-info';
        }

        return 'label-default';
    }

    function render() {
        var keyword = String(modal.find('.new-config-env-search').val() || '').toUpperCase();
        var filtered = rows.filter(function (row) {
            return !keyword || row.key.indexOf(keyword) !== -1;
        });

        if (!filtered.length) {
            modal.find('.new-config-env-rows').html('<tr><td colspan="6" class="new-config-env-empty">没有匹配数据</td></tr>');
            return;
        }

        var html = filtered.map(function (row) {
            return '<tr>'
                + '<td class="env-key">' + escapeHtml(row.key) + (row.sensitive ? ' <span class="label label-warning">脱敏</span>' : '') + '</td>'
                + '<td class="env-value">' + escapeHtml(row.final_value) + '</td>'
                + '<td class="new-config-env-source"><span class="label ' + sourceClass(row.final_source) + '">' + escapeHtml(row.final_source_label) + '</span></td>'
                + '<td class="env-value">' + escapeHtml(row.dotenv_value) + '</td>'
                + '<td class="env-value">' + escapeHtml(row.process_value) + '</td>'
                + '<td class="env-value">' + escapeHtml(row.config_center_value) + '</td>'
                + '</tr>';
        }).join('');

        modal.find('.new-config-env-rows').html(html);
    }

    function renderErrors(errors) {
        if (!errors || !errors.length) {
            modal.find('.new-config-env-errors').empty();
            return;
        }

        var html = errors.map(function (error) {
            return '<div class="alert alert-warning new-config-env-error">'
                + escapeHtml(error.source || 'env') + ': ' + escapeHtml(error.message || '')
                + '</div>';
        }).join('');
        modal.find('.new-config-env-errors').html(html);
    }

    function load() {
        modal.find('.new-config-env-rows').html('<tr><td colspan="6" class="new-config-env-empty">加载中...</td></tr>');
        $.getJSON(url).done(function (payload) {
            rows = payload.rows || [];
            var counts = payload.counts || {};
            modal.find('.new-config-env-meta').text(
                '更新时间: ' + (payload.generated_at || '-')
                + ' / 总数: ' + (counts.total || 0)
                + ' / 配置中心: ' + (counts.config_center || 0)
                + ' / 当前进程 env: ' + (counts.process_env || 0)
                + ' / .env: ' + (counts.dotenv || 0)
            );
            renderErrors(payload.errors || []);
            render();
        }).fail(function (xhr) {
            var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : '加载失败';
            modal.find('.new-config-env-rows').html('<tr><td colspan="6" class="new-config-env-empty">' + escapeHtml(message) + '</td></tr>');
        });
    }

    $('.new-config-env-preview-button').off('click').on('click', function () {
        modal.modal('show');
        load();
    });

    modal.find('.new-config-env-refresh').off('click').on('click', load);
    modal.find('.new-config-env-search').off('keyup').on('keyup', render);
})();
JS;
    }
}
