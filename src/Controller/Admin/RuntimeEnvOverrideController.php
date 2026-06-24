<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Tool\Data\NewConfig;
use Mallto\Tool\Domain\NewConfig\NewConfigBootstrapKeyGuard;
use Mallto\Tool\Domain\NewConfig\RuntimeEnvOverrideConfig;

class RuntimeEnvOverrideController extends AdminCommonController
{
    protected function getHeaderTitle()
    {
        return '运行期 Env 覆盖';
    }

    protected function getModel()
    {
        return NewConfig::class;
    }

    protected function gridOption(Grid $grid)
    {
        $grid->model()
            ->where('group_key', NewConfig::GROUP_RUNTIME_ENV_OVERRIDE)
            ->orderBy('env_key')
            ->orderBy('id');

        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableDelete();
        });

        $grid->env_key('Env Key')->copyable();
        $grid->name('配置项')->limit(30);
        $grid->type('类型')->label();
        $grid->value('当前值')->limit(40)->editable();
        $grid->default_value('默认值')->limit(30);
        $grid->is_enabled('启用')->switchE();
        $grid->requires_reload('需重启')->display(function ($value) {
            return $value
                ? '<span class="label label-warning">是</span>'
                : '<span class="label label-default">否</span>';
        });
        $grid->last_published_at('发布时间');
        $grid->last_publish_error('发布错误')->limit(40);
        $grid->remark('说明')->limit(40);

        $grid->filter(function ($filter) {
            $filter->ilike('env_key', 'Env Key');
            $filter->ilike('name', '配置项');
            $filter->equal('type', '类型')->select(RuntimeEnvOverrideConfig::TYPES);
            $filter->equal('is_enabled', '启用')->select([
                1 => '启用',
                0 => '停用',
            ]);
        });

        $grid->tools(function ($tools) {
            $tools->append($this->usageNotice());
        });
    }

    protected function formOption(Form $form)
    {
        $form->tools(function (Form\Tools $tools) {
            $tools->disableDelete();
        });

        $form->text('env_key', 'Env Key')
            ->required()
            ->help('只允许配置非启动、非连接、非密钥类运行期 env key。' . NewConfigBootstrapKeyGuard::forbiddenHint());
        $form->text('name', '配置项')->required();
        $form->select('type', '类型')
            ->options(RuntimeEnvOverrideConfig::TYPES)
            ->default('string')
            ->required();
        $form->textarea('value', '当前值')
            ->help('boolean 建议填写 0/1；integer/float 会按类型归一化。');
        $form->textarea('default_value', '默认值');
        $form->textarea('remark', '说明')
            ->help('说明按纯文本保存，避免在可编辑字段中渲染 HTML。');
        $form->switch('is_enabled', '启用')->default(1);
        $form->display('requires_reload', '需重启')->with(function ($value) {
            return $value ? '是' : '否';
        })->help('运行期 Env 覆盖固定需要发布并重启后被 LaravelS/Horizon 新进程读取。');
        $form->display('last_published_at', '最近发布时间');
        $form->display('last_publish_error', '最近发布错误');

        $form->saving(function (Form $form) {
            $model = $form->model();
            $attributes = RuntimeEnvOverrideConfig::attributesFor(
                (string)$form->env_key,
                (string)$form->name,
                (string)$form->type,
                $form->value === null ? null : (string)$form->value,
                $form->default_value === null ? null : (string)$form->default_value,
                $form->remark === null ? null : (string)$form->remark,
                $this->normalizeEnabled($form->is_enabled)
            );

            RuntimeEnvOverrideConfig::assertUniqueEnvKey(
                $attributes['env_key'],
                $model->id ? (int)$model->id : null
            );

            foreach ($attributes as $key => $value) {
                $model->{$key} = $value;
            }
        });
    }

    private function usageNotice(): string
    {
        $publishRestartUrl = route('new_configs.publish_restart');

        return '<div class="alert alert-warning" style="margin:10px 0 12px;">'
            . '<strong>提示：</strong>这里不是 .env 文件编辑器，只会写入配置中心运行期 Env 覆盖。'
            . '保存后还需要到 '
            . '<a href="' . $this->escape($publishRestartUrl) . '">发布与重启</a>'
            . ' 页面执行发布并重启，LaravelS/Horizon 新进程才会读取最新值。'
            . '</div>';
    }

    private function normalizeEnabled($value): bool
    {
        $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $normalized ?? false;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
