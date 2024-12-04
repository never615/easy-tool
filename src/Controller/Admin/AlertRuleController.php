<?php

namespace Mallto\Tool\Controller\Admin;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Tool\Data\AlertRule;


class AlertRuleController extends AdminCommonController
{


    /**
     * 获取这个模块的标题
     *
     * @return mixed
     */
    protected function getHeaderTitle()
    {
        return "报警配置";
    }

    /**
     * 获取这个模块的Model
     *
     * @return mixed
     */
    protected function getModel()
    {
        return AlertRule::class;
    }

    protected function gridOption(Grid $grid)
    {
        $grid->alert_type();
        $grid->rule_name();
        $grid->level();
        $grid->source();
        $grid->alert_name();
        $grid->asset_name();
        $grid->asset_id();
        $grid->contact_id();
        $grid->email();
        $grid->mobile();
        $grid->alert_time();
        $grid->alert_desc();
        $grid->alert_name_en();
        $grid->webhook();
        $grid->silence_time();
        $grid->enable();
        $grid->threshold();


    }

    /**
     * 需要实现的form设置
     *
     * 如果需要使用tab,则需要复写defaultFormOption()方法,
     * 然后formOption留空即可
     *
     * @param Form $form
     * @return mixed
     */
    protected function formOption(Form $form)
    {
        $form->text("alert_type");
        $form->text("rule_name");
        $form->text("level");
        $form->text("source");
        $form->text("alert_name");
        $form->text("asset_name");
        $form->text("asset_id");
        $form->text("contact_id");
        $form->text("email");
        $form->text("mobile");
        $form->text("alert_time");
        $form->text("alert_desc");
        $form->text("alert_name_en");
        $form->text("webhook");
        $form->text("silence_time");
        $form->text("enable");
        $form->text("threshold");

    }
}
