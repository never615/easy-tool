<?php
/**
 * Copyright () 2018. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Tool\Data\ApiPv;
use Mallto\Tool\Data\ApiPvManager;
use Mallto\Tool\Domain\Traits\SlugAutoSave;

/**
 * ApiPvManagerController：处理 api pv manager controller 相关的 HTTP 请求。
 * 控制器负责参数和响应边界，领域规则交由服务或模型处理。
 */
class ApiPvManagerController extends AdminCommonController
{

    use SlugAutoSave;


    /**
     * 获取这个模块的标题
     *
     * @return mixed
     */
    protected function getHeaderTitle()
    {
        return "api pv管理";
    }


    /**
     * 处理「getIndexDesc」相关的内部协作步骤。
     */
    protected function getIndexDesc()
    {
        return "主要用于模块统计";
    }


    /**
     * 获取这个模块的Model
     *
     * @return mixed
     */
    protected function getModel()
    {
        return ApiPvManager::class;
    }


    /**
     * 处理「gridOption」相关的内部协作步骤。
     */
    protected function gridOption(Grid $grid)
    {
        $grid->path("接口");
        $grid->name();
        $grid->slug();
//        $grid->switch("统计页是否显示")->switch();

    }


    /**
     * 需要实现的form设置
     *
     * 如果需要使用tab,则需要复写defaultFormOption()方法,
     * 然后formOption留空即可
     *
     * @param Form $form
     *
     * @return mixed
     */
    protected function formOption(Form $form)
    {
        $form->select("path", "接口")
            ->options(ApiPv::selectSourceDatas2()->pluck("path", "path"))
            ->rules("required");
        $form->text("name")->rules("required");
        $form->text("slug")->required();
//        $form->switch("switch", "统计页是否显示");
        $form->hidden("switch")->default(true);
        $form->textarea("remark");


    }
}
