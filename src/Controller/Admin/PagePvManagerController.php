<?php
/**
 * Copyright (c) 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Illuminate\Http\Request;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Controller\Admin\Traits\GetAdTypes;
use Mallto\Tool\Data\Ad;
use Mallto\Tool\Data\PagePv;
use Mallto\Tool\Data\PagePvManager;
use Mallto\Tool\Domain\Traits\SlugAutoSave;


class PagePvManagerController extends AdminCommonController
{

    use SlugAutoSave,GetAdTypes;

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
        $grid->switch("统计页是否显示")->switch();
        $grid->slug();
        $grid->weight()->editable();
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
        $form->selectOrNew("path", "页面路径")
            ->options(PagePv::selectSourceDatas2()->pluck("path", "path"))
            ->rules("required");
        $form->text("name")->rules("required");
        $form->text("slug")->rules("required");

        $form->multipleSelect("ad_types", "支持的广告类型")
            ->default("float_image")
            ->options(AD::AD_TYPE)
            ->help("浮动广告必须有")
            ->required();

        $form->switch("switch", "统计页是否显示");
        $form->textarea("remark");
        $form->text("weight");

        $form->saving(function ($form) {
//            $this->slugSavingCheck($form, $this->getModel());
        });
    }


    public function getPageAdType(Request $request)
    {
        $q = $request->get('q');

        $adminUser = Admin::user();

        $subject = SubjectUtils::getSubject();
        if (!$subject) {
            $subject = $adminUser->subject;
        }

        //查询子主体
        $childSubjectIds = $subject->getChildrenSubject();

        $pagePvManager = PagePvManager::whereIn("subject_id", $childSubjectIds)
            ->where("path", $q)
            ->first();

        return $this->getAdTypes($pagePvManager);
    }




}
