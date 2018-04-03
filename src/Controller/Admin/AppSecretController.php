<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin;


use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Tool\Data\AppSecret;

/**
 * Class LogController
 *
 * @package Mallto\Mall\Controller
 */
class AppSecretController extends AdminCommonController
{
    /**
     * 获取这个模块的标题
     *
     * @return mixed
     */
    protected function getHeaderTitle()
    {
        return "app secret管理";
    }

    /**
     * 获取这个模块的Model
     *
     * @return mixed
     */
    protected function getModel()
    {
        return AppSecret::class;
    }

    protected function gridOption(Grid $grid)
    {
        $grid->name();
        $grid->app_id();
        $grid->app_secret();

        $grid->filter(function (Grid\Filter $filter) {
            $filter->ilike("app_id");
            $filter->ilike("name");
        });

    }

    protected function formOption(Form $form)
    {
        $form->text("name");
        $form->text('app_id');
        $form->text('app_secret')
            ->help("﻿openssl rand -hex 16");
    }

}
