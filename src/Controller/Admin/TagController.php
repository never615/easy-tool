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
use Mallto\Admin\SubjectConfigConstants;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Data\Tag;
use Mallto\Tool\Domain\Traits\SlugAutoSave;

class TagController extends AdminCommonController
{

    use  SlugAutoSave;

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


    private function getSelectTagTypes()
    {
        $tagTypes = SubjectUtils::getConfigByOwner(SubjectConfigConstants::OWNER_CONFIG_TAG_TYPES);
        if (!$tagTypes) {
            return Tag::TYPE;
        } else {
            return array_only(Tag::TYPE, $tagTypes);
        }
    }

    /**
     * @param Grid $grid
     */
    protected function gridOption(Grid $grid)
    {
        $type = \Request::input("type");
        if ($type) {
            $grid->model()->where("type", $type);
        }

        $grid->name()->editable()->sortable();
        $grid->type()->select(
            $this->getSelectTagTypes()
        )->sortable();

        $grid->filter(function (Grid\Filter $filter) {
            $filter->equal('type')->select($this->getSelectTagTypes());
            $filter->ilike("name");
        });
    }

    /**
     * @param Form $form
     * @return mixed|void
     */
    protected function formOption(Form $form)
    {
        $type = \Request::input("type");


        $form->text('name')->rules('required');

        if ($type) {
            $form->select('type')
                ->default($type)
                ->options(
                    $this->getSelectTagTypes()

                )
                ->rules("required");
        } else {
            $form->select('type')
                ->default("common")
                ->options(
                    $this->getSelectTagTypes()
                )
                ->rules("required");
        }


        if ($this->currentId && \Mallto\Admin\AdminUtils::isOwner()) {
            $form->displayE("slug");
        }

        $form->image("logo")
            ->uniqueName()
            ->removable()
            ->move('tag/logo/'.$this->currentId);

        $form->saving(function ($form) {
            $type = $form->type ?: $form->model()->type;
            //自动生成标识
            $this->slugSavingCheck($form, $this->getModel(), "slug",
                "type", $type);
        });
    }
}
