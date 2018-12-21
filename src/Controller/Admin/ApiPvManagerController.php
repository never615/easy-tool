<?php
/**
 * Copyright () 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Tool\Data\ApiPv;
use Mallto\Tool\Data\ApiPvManager;
use Mallto\Tool\Domain\Traits\SlugAutoSave;


class ApiPvManagerController extends AdminCommonController
{

    use SlugAutoSave;

    /**
     * 获取这个模块的标题
     *
     * @return mixed
     */
    protected function getHeaderTitle()
    {
        return "api pv管理";
    }

    /**
     * 获取这个模块的Model
     *
     * @return mixed
     */
    protected function getModel()
    {
        return ApiPvManager::class;
    }

    protected function gridOption(Grid $grid)
    {
        $grid->path("页面路径");
        $grid->name();
        $grid->slug();
//        $grid->switch("统计页是否显示")->switch();

    }

    /**
     * 需要实现的form设置
     *
     * 如果需要使用tab,则需要复写defaultFormOption()方法,
     * 然后formOption留空即可
     *
     * @param Form $form
     * @return mixed
     */
    protected function formOption(Form $form)
    {
        $form->select("path", "页面路径")
            ->options(ApiPv::selectSourceDatas2()->pluck("path", "path"))
            ->rules("required");
        $form->text("name")->rules("required");
        $form->text("slug");
//        $form->switch("switch", "统计页是否显示");
        $form->textarea("remark");


        $form->saving(function ($form) {
//            $this->slugSavingCheck($form, $this->getModel());
        });

    }
}
