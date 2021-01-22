<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Tool\Data\AppSecretsPermission;
use Mallto\Tool\Data\AppSecretsRole;

class AppSecretRoleController extends AdminCommonController
{

    /**
     * 获取这个模块的标题
     *
     * @return mixed
     */
    protected function getHeaderTitle()
    {
        return '开发者角色管理';
    }


    /**
     * 获取这个模块的Model
     *
     * @return mixed
     */
    protected function getModel()
    {
        return AppSecretsRole::class;
    }


    protected function gridOption(Grid $grid)
    {
        $grid->column('name', '角色名称')->editable();
        $grid->column('slug', '角色标识')->editable();

        $grid->filter(function (Grid\Filter $filter) {
            $filter->ilike('name', '角色名称');
            $filter->ilike('slug', '角色标识');
        });

        $grid->disableExport();
    }


    protected function formOption(Form $form)
    {
        $form->text('name');
        $form->textarea('slug');
        $form->checkbox('permissions', trans('admin.permissions'))
            ->options(function () {
                return AppSecretsPermission::query()->orderBy('created_at')->get()->pluck('name',
                    'id')->toArray();
            })
            ->stacked();
    }

}
