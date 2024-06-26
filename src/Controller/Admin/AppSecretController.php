<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Admin\Data\Subject;
use Mallto\Admin\SubjectUtils;
use Mallto\Tool\Data\AppSecret;
use Mallto\Tool\Data\AppSecretsRole;

/**
 * Class AppSecretController
 *
 * @package Mallto\Tool\Controller\Admin
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
        return '开发者管理';
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
        $grid->name()->editable();
        $grid->remark()->editable();
        $grid->app_id();
        $grid->app_secret();

        $grid->switch()->switchE();

        $grid->column('is_check_third_permission', '是否校验接口权限')->switchE();

        $grid->column('roles', trans('admin.roles'))->pluck('name')->label();

        $grid->column('app_secret_subjects', '主体')->pluck('name')->label();

        $grid->filter(function (Grid\Filter $filter) {
            $filter->ilike("app_id");
            $filter->ilike("name");
        });

    }


    protected function formOption(Form $form)
    {
        $form->text("name");
        $form->textarea("remark");
        $form->text('app_id');
        $form->text('app_secret')
            ->help("﻿openssl rand -hex 16");
        $form->multipleSelect('roles', '角色')
            ->options(AppSecretsRole::query()->get()->pluck('name', 'id'));
        $form->multipleSelect('app_secret_subjects', '主体')
            ->options(Subject::DynamicData()->get()->pluck('name', 'id'));
        $form->switch("switch");
        $form->switch("is_check_third_permission", '是否校验接口权限');
    }

}
