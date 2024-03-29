<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Tool\Data\Feedback;

/**
 * Class FeedBackController
 *
 * @package Mallto\Tool\Controller\Admin
 */
class FeedBackController extends AdminCommonController
{

    /**
     * 获取这个模块的标题
     *
     * @return mixed
     */
    protected function getHeaderTitle()
    {
        return "app意见反馈";
    }


    /**
     * 获取这个模块的Model
     *
     * @return mixed
     */
    protected function getModel()
    {
        return Feedback::class;
    }


    protected function gridOption(Grid $grid)
    {
        $grid->mobile();
        $grid->content()->limit(250);

        $grid->disableCreation();
        $grid->filter(function (Grid\Filter $filter) {
            $filter->ilike("content");
            $filter->ilike("mobile");
        });


    }


    protected function formOption(Form $form)
    {
        $form->displayE("mobile");
        $form->displayE("content", "反馈内容");
    }

}
