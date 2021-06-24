<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Tool\Controller\Admin\Traits\GetAdTypes;
use Mallto\Tool\Data\Ad;
use Mallto\Tool\Data\PagePv;
use Mallto\Tool\Data\PagePvManager;
use Mallto\Tool\Domain\Traits\SlugAutoSave;

class PagePvManagerController extends AdminCommonController
{

    use SlugAutoSave;

    /**
     * 获取这个模块的标题
     *
     * @return mixed
     */
    protected function getHeaderTitle()
    {
        return "微信页面管理";
    }


    /**
     * 获取这个模块的Model
     *
     * @return mixed
     */
    protected function getModel()
    {
        return PagePvManager::class;
    }


    protected function gridOption(Grid $grid)
    {
        $grid->path("页面路径");
        $grid->name();
        $grid->switch("统计页是否显示")->switchE();
        $grid->slug()
            ->help('暂时没用');
        
        $grid->weight()->editable();
    }


    /**
     * 需要实现的form设置
     *
     * 如果需要使用tab,则需要复写defaultFormOption()方法,
     * 然后formOption留空即可
     *
     * @param Form $form
     *
     * @return mixed
     */
    protected function formOption(Form $form)
    {
        $form->selectOrNew("path", "页面路径")
            ->options(PagePv::selectSourceDatas2()->pluck("path", "path"))
            ->rules("required");
        $form->text("name")->rules("required");
        $form->text("slug")
            ->help('暂时没用')
            ->rules("required");

        $form->multipleSelect("ad_types", "支持的广告类型")
            ->default("float_image")
            ->options(AD::AD_TYPE)
            ->help("浮动广告必须有")
            ->required();

        $form->switch("switch", "统计页是否显示");
        $form->textarea("remark");
        $form->text("weight")->default(0);

        $form->saving(function ($form) {
//            $this->slugSavingCheck($form, $this->getModel());
        });
    }

}
