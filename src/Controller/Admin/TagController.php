<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin;


use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Illuminate\Support\Facades\Input;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Mall\Domain\Traits\TagAutoSave;
use Mallto\Tool\Data\Tag;

class TagController extends AdminCommonController
{

    use  TagAutoSave;

    /**
     * 获取这个模块的标题
     *
     * @return mixed
     */
    protected function getHeaderTitle()
    {
        return "标签/类型管理";
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
        $type = Input::get("type");
        if ($type) {
            $grid->model()->where("type", $type);
        }

        $grid->name()->editable()->sortable();
        $grid->type()->select(Tag::TYPE)->sortable();

        $grid->filter(function (Grid\Filter $filter) {
            $filter->equal('type')->select(Tag::TYPE);
            $filter->ilike("name");
        });
    }

    /**
     * @param Form $form
     * @return mixed|void
     */
    protected function formOption(Form $form)
    {
        $type = Input::get("type");


        $form->text('name')->rules('required');

        if ($type) {
            $form->select('type')
                ->default($type)
                ->options(Tag::TYPE)
                ->rules("required");
        } else {
            $form->select('type')
                ->default("common")
                ->options(Tag::TYPE)
                ->rules("required");
        }


        if ($this->currentId && Admin::user()->isOwner()) {
            $form->display("slug");
        }

        $form->image("logo")
            ->uniqueName()
            ->removable()
            ->move('tag/logo/'.$this->currentId);

        $form->saving(function ($form) {
            $type = $form->type ?: $form->model()->type;
            //自动生成标识
            $this->tagSavingCheck($form, $this->getModel(), "slug",
                "type", $type);
        });
    }
}
