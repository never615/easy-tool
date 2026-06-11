<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Tool\Data\NewConfig;

class NewConfigController extends AdminCommonController
{
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
        $grid->requires_reload('Reload')->switchE();
        $grid->last_published_at('发布时间');
        $grid->last_publish_error('发布错误')->limit(40);

        $grid->filter(function ($filter) {
            $filter->ilike('key', 'Key');
            $filter->ilike('env_key', 'Env Key');
            $filter->ilike('name', '配置项');
            $filter->equal('group_key', '分组');
        });
    }

    protected function formOption(Form $form)
    {
        $form->text('group_key', '分组')->required();
        $form->text('key', 'Key')->required();
        $form->text('env_key', 'Env Key')
            ->help('发布到 .env 的环境变量名，例如 SWOOLE_TASK_MONITOR_ENABLED。留空则不写入 .env。');
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
        $form->switch('requires_reload', '保存后 Reload')->default(1)
            ->help('开启时，后台保存配置后会写入 .env 并执行 bin/laravels reload。');
        $form->display('last_published_at', '最近发布时间');
        $form->display('last_publish_error', '最近发布错误');
    }
}
