<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin;


use Mallto\Admin\Controllers\Base\AdminCommonController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Tool\Data\Tag;

class TagController extends AdminCommonController
{

    /**
     * 获取这个模块的标题
     *
     * @return mixed
     */
    protected function getHeaderTitle()
    {
        return "标签管理";
    }

    /**
     * 获取这个模块的Model
     *
     * @return mixed
     */
    protected function getModel()
    {
        return Tag::class;
    }

    /**
     * @param Grid $grid
     */
    protected function gridOption(Grid $grid)
    {
        $grid->name()->editable()->sortable();
        $grid->type()->select(Tag::TYPE)->sortable();
        $grid->filter(function (Grid\Filter $filter) {
            $filter->equal('type')->select(Tag::TYPE);
            $filter->ilike("name");
        });
    }

    /**
     * @param Form $form
     */
    protected function formOption(Form $form)
    {
        $form->text('name')->rules('required');
        $form->select('type')
            ->default("common")
            ->options(Tag::TYPE);
    }
}
