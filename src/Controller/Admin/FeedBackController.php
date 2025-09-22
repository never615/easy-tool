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
        return trans("admin.feedback_header_title");
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
        $grid->mobile(trans("admin.mobile"));
        $grid->content(trans("admin.content"))->limit(250);

        $grid->disableCreation();
        $grid->filter(function (Grid\Filter $filter) {
            $filter->ilike("content", trans("admin.content"));
            $filter->ilike("mobile", trans("admin.mobile"));
        });


    }


    protected function formOption(Form $form)
    {
        $form->displayE("mobile", trans("admin.mobile"));
        $form->displayE("content", trans("admin.content"));
    }

}