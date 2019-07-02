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


use Encore\Admin\Form;
use Encore\Admin\Grid;
use Mallto\Admin\AdminUtils;
use Mallto\Admin\Controllers\Base\AdminCommonController;
use Mallto\Admin\Data\Subject;
use Mallto\Tool\Data\WechatTemplateMsg;
use Mallto\Tool\Domain\Wechat\WechatUsecase;
use Mallto\Tool\Domain\Wechat\WechatUtils;
use Mallto\Tool\Exception\ResourceException;

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
        $grid->public_template_id("微信模板消息")->display(function ($value) {
            return WechatUtils::getTemplateIds()[$value] ?? "";
        });


        if (!AdminUtils::isOwner()) {
            $grid->disableCreateButton();
            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $actions->disableDelete();
                $actions->disableView();
            });
        }


    }

    protected function formOption(Form $form)
    {

        if (\Mallto\Admin\AdminUtils::isOwner()) {
            $form->displayE("template_id", "公众号对应的模板id");

            $form->select("public_template_id", "微信消息模板")
                ->options(WechatUtils::getTemplateIds())
                ->rules("required")
                ->help(("所有的模板消息均属于消费品行业下,如果公众号所在行业未设置或不是消费品行业,则无法使用模板消息(公众号所属行业在公众号后台模板消息中修改)"));

        } else {
            $form->displayE("public_template_id", "微信消息模板")->with(function ($value) {
                return WechatUtils::getTemplateIds()[$value] ?? $value;
            });
        }

        $form->text("template_remark", "消息模板备注")
            ->help('此填写内容会出现在模板消息的备注位置<br>
注意模板消息不能乱配置,否则会被处罚.详情规则参见<a href="https://mp.weixin.qq.com/wiki?t=resource/res_main&id=mp1433751288" target="_blank">微信模板消息运营规范</a>');

        $form->text("template_link", "消息模板的跳转链接")
            ->help("配置此链接后,用户收到的模板消息会出现可以点击的跳转链接");

        $form->saving(function ($form) {
            //创建公众号对应模板id
            if ($form->public_template_id &&
                $form->public_template_id != $form->model()->public_template_id) {

                $subject = Subject::find($form->subject_id ?? $form->model()->subject_id);

                $wechatUsecase = app(WechatUsecase::class);
                $templateId = $wechatUsecase->addTemplateId($form->public_template_id, $subject);
                if (!$templateId) {
                    throw new ResourceException("消息模板设置失败");
                }
                $form->model()->template_id = $templateId;
            }
        });
    }
}
