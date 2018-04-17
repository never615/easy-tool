<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

/**
 * Created by PhpStorm.
 * User: never615 <never615.com>
 * Date: 17/07/2017
 * Time: 10:44 AM
 */

namespace Mallto\Tool\Controller\Admin;


use Mallto\Admin\Controllers\Base\AdminCommonController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Tool\Data\WechatTemplateMsg;

/**
 * 微信模板消息配置管理
 * 因为同一个模板的模板id在不同公众号中是不一样的,所以各个主体需要各自配置
 *
 * Class WechatTemplateMsgContoller
 *
 * @package Mallto\Activity\Controller\Admin
 */
class WechatTemplateMsgContoller extends AdminCommonController
{

    /**
     * 获取这个模块的标题
     *
     * @return mixed
     */
    protected function getHeaderTitle()
    {
        return "微信模板消息id管理";
    }

    /**
     * 获取这个模块的Model
     *
     * @return mixed
     */
    protected function getModel()
    {
        return WechatTemplateMsg::class;
    }

    protected function gridOption(Grid $grid)
    {
        $grid->public_template_id("公共id")->editable();
        $grid->template_id("公众号对应的id")->editable();
        $grid->remark("备注")->editable();
    }

    protected function formOption(Form $form)
    {
        $form->text("public_template_id", "微信公共模板id");
        $form->text("template_id", "公众号对应的模板id");
        $form->text("remark","备注");
    }
}
