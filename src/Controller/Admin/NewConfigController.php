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
        $grid->type('类型')->label();
        $grid->value('当前值')->editable()->limit(30);
        $grid->default_value('默认值')->limit(30);
        $grid->remark('说明')->limit(40);
        $grid->sort('排序')->editable();
        $grid->is_enabled('启用')->switch();
        $grid->updated_at('更新时间');

        $grid->filter(function ($filter) {
            $filter->ilike('key', 'Key');
            $filter->ilike('name', '配置项');
            $filter->equal('group_key', '分组');
        });
    }

    protected function formOption(Form $form)
    {
        $form->text('group_key', '分组')->required();
        $form->text('key', 'Key')->required();
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
    }
}
