<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Tool\Data\PageBanner;

/**
 * PageBannerController：处理 page banner controller 相关的 HTTP 请求。
 * 控制器负责参数和响应边界，领域规则交由服务或模型处理。
 */
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


    /**
     * 处理「gridOption」相关的内部协作步骤。
     */
    protected function gridOption(Grid $grid)
    {
        $grid->image()->image("", 50, 50);

        $grid->link()->editable();

        $grid->weight()->editable();
    }


    /**
     * 处理「formOption」相关的内部协作步骤。
     */
    protected function formOption(Form $form)
    {
        $form->text('link')->help("链接需要填写http://或者https://");
        $form->image('image')
            ->uniqueName()
            ->removable()
            ->move('page_banner/image' . $this->currentId)
            ->rules("required");
        $form->text("weight")->default(0)->rules("numeric");
    }

}
