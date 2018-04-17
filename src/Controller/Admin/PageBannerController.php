<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin;



use Mallto\Admin\Controllers\Base\AdminCommonController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Tool\Data\PageBanner;


class PageBannerController extends AdminCommonController
{

    /**
     * 获取这个模块的标题
     *
     * @return mixed
     */
    protected function getHeaderTitle()
    {
        return "轮播图";
    }

    /**
     * 获取这个模块的Model
     *
     * @return mixed
     */
    protected function getModel()
    {
        return PageBanner::class;
    }


    protected function gridOption(Grid $grid)
    {
        $grid->image()->image("", 50, 50);

        $grid->link()->editable();

        $grid->weight()->editable();
    }


    protected function formOption(Form $form)
    {
        $form->text('link')->help("链接需要填写http://或者https://");
        $form->image('image')
            ->uniqueName()
            ->move('page_banner/image'.$this->currentId)
            ->rules("required");
        $form->text("weight")->default(0)->rules("numeric");
    }

}
