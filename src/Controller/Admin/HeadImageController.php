<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin;



use Mallto\Admin\Controllers\Base\AdminCommonController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Tool\Data\HeadImage;


class HeadImageController extends AdminCommonController
{

    /**
     * 获取这个模块的标题
     *
     * @return mixed
     */
    protected function getHeaderTitle()
    {
        return "模块头图配置";
    }

    /**
     * 获取这个模块的Model
     *
     * @return mixed
     */
    protected function getModel()
    {
        return HeadImage::class;
    }


    protected function gridOption(Grid $grid)
    {
        $grid->type()->select(HeadImage::TYPE);
    }


    protected function formOption(Form $form)
    {
        $form->select("type")
            ->options(HeadImage::TYPE)
            ->help("一个模块只能设置一个头图");
        $form->image('image')
            ->uniqueName()
            ->move('head_image/image'.$this->currentId)
            ->rules("required");
    }

}
