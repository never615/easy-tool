<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Tool\Data\AppSecretsPermission;

/**
 * AppSecretPermissionController：处理 app secret permission controller 相关的 HTTP 请求。
 * 控制器负责参数和响应边界，领域规则交由服务或模型处理。
 */
class AppSecretPermissionController extends AdminCommonController
{

    /**
     * 获取这个模块的标题
     *
     * @return mixed
     */
    protected function getHeaderTitle()
    {
        return '开发者接口权限管理';
    }


    /**
     * 获取这个模块的Model
     *
     * @return mixed
     */
    protected function getModel()
    {
        return AppSecretsPermission::class;
    }


    /**
     * 处理「gridOption」相关的内部协作步骤。
     */
    protected function gridOption(Grid $grid)
    {
        $grid->column('name', '权限名称');
        $grid->column('slug', '权限标识');

        $grid->filter(function (Grid\Filter $filter) {
            $filter->ilike('name', '权限名称');
            $filter->ilike('slug', '权限标识');
        });

        $grid->disableCreateButton();
        $grid->disableExport();

    }


    /**
     * 处理「formOption」相关的内部协作步骤。
     */
    protected function formOption(Form $form)
    {
        $form->text('name');
        $form->textarea('slug');
    }

}
